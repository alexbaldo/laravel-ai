<?php

namespace Laravel\Ai\Gateway\OpenAi;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\Concerns\ResolvesAudioFilenames;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use LogicException;

class OpenAiGateway implements Gateway, StepTextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesOpenAiClient;
    use Concerns\GeneratesImagesViaResponses;
    use Concerns\HandlesTextGeneration;
    use Concerns\HandlesTextSteps;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use ParsesServerSentEvents;
    use ResolvesAudioFilenames;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * Generate an image.
     *
     * @param  array<Image>  $attachments
     * @param  '3:2'|'2:3'|'1:1'|null  $size
     * @param  'low'|'medium'|'high'|null  $quality
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        return $this->withErrorHandling(
            $provider->name(),
            fn (): ImageResponse => match (true) {
                // At least one non-image attachment: the Images API cannot
                // read documents, but the Responses API `image_generation`
                // tool (GI-A1/GI-A2) can. R2: the two branches below this
                // one are untouched, so this is the only new branch (GI-A3).
                $this->hasNonImageAttachment($attachments) => $this->generateImageViaResponses(
                    $provider, $model, $prompt, $attachments, $size, $timeout,
                ),
                filled($attachments) => $this->buildImageResponse(
                    $this->sendImageEditRequest($provider, $model, $prompt, $attachments, $size, $quality, $timeout),
                    $provider, $model,
                ),
                default => $this->buildImageResponse(
                    $this->sendImageGenerationRequest($provider, $model, $prompt, $size, $quality, $timeout),
                    $provider, $model,
                ),
            },
        );
    }

    /**
     * Build an `ImageResponse` from a classic Images API response
     * (`images/generations` or `images/edits`).
     */
    protected function buildImageResponse(Response $response, ImageProvider $provider, string $model): ImageResponse
    {
        $data = $response->json();

        return new ImageResponse(
            collect($data['data'] ?? [])->map(fn (array $image): GeneratedImage => new GeneratedImage(
                $image['b64_json'] ?? '',
                'image/png',
            )),
            $this->extractImageUsage($data),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Extract usage from an Images API response.
     *
     * The Images API marks output token details as optional; when they are
     * missing, every output token it bills is an image token.
     */
    protected function extractImageUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];
        $outputDetails = $usage['output_tokens_details'] ?? null;

        return new Usage(
            inputTokens: [
                'text' => $usage['input_tokens_details']['text_tokens'] ?? $usage['input_tokens'] ?? 0,
                'image' => $usage['input_tokens_details']['image_tokens'] ?? 0,
            ],
            outputTokens: [
                'text' => $outputDetails['text_tokens'] ?? 0,
                'image' => $outputDetails ? ($outputDetails['image_tokens'] ?? 0) : ($usage['output_tokens'] ?? 0),
            ],
            cachedTokens: [
                'text' => $usage['input_tokens_details']['cached_tokens_details']['text_tokens'] ?? $usage['input_tokens_details']['cached_tokens'] ?? 0,
                'image' => $usage['input_tokens_details']['cached_tokens_details']['image_tokens'] ?? 0,
            ],
        );
    }

    /**
     * Send an image generation request.
     */
    protected function sendImageGenerationRequest(
        ImageProvider $provider,
        string $model,
        string $prompt,
        ?string $size,
        ?string $quality,
        ?int $timeout,
    ) {
        return $this->client($provider, $timeout ?? 120)->post('images/generations', [
            'model' => $model,
            'prompt' => $prompt,
            ...$provider->defaultImageOptions($size, $quality),
            ...(str_starts_with($model, 'gpt-image')
                ? ['moderation' => 'low']
                : []),
        ]);
    }

    /**
     * Send an image edit request with attachments.
     */
    protected function sendImageEditRequest(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments,
        ?string $size,
        ?string $quality,
        ?int $timeout,
    ) {
        $request = $this->client($provider, $timeout ?? 120);

        $isGptImage = str_starts_with($model, 'gpt-image');
        $field = $isGptImage ? 'image[]' : 'image';

        foreach ($attachments as $attachment) {
            $content = match (true) {
                $attachment instanceof Image && $attachment instanceof StorableFile => $attachment->content(),
                $attachment instanceof UploadedFile => $attachment->get(),
                default => throw new InvalidArgumentException('Unsupported image attachment type ['.get_debug_type($attachment).']'),
            };

            $request = $request->attach($field, $content, 'image.png');
        }

        return $request->post('images/edits', array_filter([
            'model' => $model,
            'prompt' => $prompt,
            ...$provider->defaultImageOptions($size, $quality),
            ...($isGptImage
                ? ['moderation' => 'low']
                : []),
        ]));
    }

    /**
     * Generate audio from the given text.
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        $voice = match ($voice) {
            'default-male' => 'ash',
            'default-female' => 'alloy',
            default => $voice,
        };

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('audio/speech', array_filter([
                'model' => $model,
                'input' => $text,
                'voice' => $voice,
                'response_format' => 'mp3',
                'speed' => 1.0,
                'instructions' => $instructions,
            ])),
        );

        return new AudioResponse(
            base64_encode($response->body()),
            new Meta($provider->name(), $model),
            'audio/mpeg',
        );
    }

    /**
     * Generate text from the given audio.
     *
     * @param  array<string, mixed>  $providerOptions
     *
     * @throws LogicException if diarization is requested together with the `prompt` provider option.
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        if ($diarize && filled($providerOptions['prompt'] ?? null)) {
            throw new LogicException('OpenAI does not support the `prompt` option for diarized transcriptions.');
        }

        if ($provider->driver() === 'openai' && ! $diarize) {
            $model = str_replace('-diarize', '', $model);
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->attach('file', $audio->content(), $this->audioFilename($audio), array_filter(['Content-Type' => $audio->mimeType()]))
                ->post('audio/transcriptions', array_merge($providerOptions, array_filter([
                    'model' => $model,
                    'language' => $language,
                    'response_format' => $diarize ? 'diarized_json' : 'json',
                ]))),
        );

        $data = $response->json();

        return new TranscriptionResponse(
            $data['text'] ?? '',
            collect($data['segments'] ?? [])->map(fn (array $segment): TranscriptionSegment => new TranscriptionSegment(
                $segment['text'] ?? '',
                $segment['speaker'] ?? '',
                $segment['start'] ?? 0,
                $segment['end'] ?? 0,
            )),
            $this->extractUsage($data),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', array_merge($providerOptions, [
                'model' => $model,
                'input' => $inputs,
                'dimensions' => $dimensions,
            ])),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            collect($data['data'] ?? [])->pluck('embedding')->all(),
            new Usage(['text' => $data['usage']['prompt_tokens'] ?? 0]),
            new Meta($provider->name(), $model),
        );
    }
}

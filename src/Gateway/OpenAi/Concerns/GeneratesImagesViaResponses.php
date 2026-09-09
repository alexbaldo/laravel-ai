<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Exceptions\ImageGenerationFailedException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Providers\Tools\ImageGeneration;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

trait GeneratesImagesViaResponses
{
    /**
     * Generate an image through the Responses API `image_generation` tool
     * (GI-A1/GI-A2), for calls carrying at least one non-image attachment
     * that the classic Images API cannot read (`OpenAiGateway::generateImage()`,
     * GI-A3, is the only caller). Adapts the tool's output to an
     * `ImageResponse`, the same return type the Images API paths produce,
     * so the dispatch in `generateImage()` stays a plain `match`.
     */
    protected function generateImageViaResponses(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments,
        ?string $size,
        ?int $timeout,
    ): ImageResponse {
        $data = $this->client($provider, $timeout ?? 120)->post('responses', [
            'model' => $this->imageGenerationCarrierModel(),
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt],
                    ...$this->mapAttachments(collect($attachments), $provider),
                ],
            ]],
            'tools' => $this->mapTools(
                [new ImageGeneration($model, $this->imageGenerationAspectFromSize($size))],
                $provider,
            ),
        ])->json();

        return new ImageResponse(
            collect([$this->extractGeneratedImageFromResponse($data['output'] ?? [])]),
            // The carrier model's own token usage (`usage`) and the image
            // tool's (`tool_usage.image_gen`) are both on this payload, but
            // reading them is GI-A5's job, not this one -- left empty here
            // on purpose rather than guessed at.
            new Usage,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Determine if at least one of the given attachments is not an image.
     *
     * Mirrors the classification `MapsAttachments::mapAttachments()` already
     * makes for OpenAI content parts, so this is not a second, possibly
     * diverging source of truth for "is this attachment an image": any
     * `Image` subclass (`Base64Image`, `LocalImage`, `RemoteImage`, ...) is
     * one by construction; a generic `UploadedFile` is classified by its
     * MIME type via `MapsAttachments::isImage()`, the same helper the text
     * path uses; anything else reaching here (a `Document` subclass, an
     * `Audio`/`Video` attachment) is not an image.
     *
     * @param  array<int, mixed>  $attachments
     */
    protected function hasNonImageAttachment(array $attachments): bool
    {
        return collect($attachments)->contains(fn ($attachment): bool => match (true) {
            $attachment instanceof Image => false,
            $attachment instanceof UploadedFile => ! $this->isImage($attachment),
            default => true,
        });
    }

    /**
     * Get the text model that carries the `image_generation` tool call
     * through the Responses API.
     *
     * Hardcoded for now, per the spike (`gpt-5.4-nano`): GI-A4 makes this
     * configurable and pinned instead of a literal buried in the gateway.
     */
    protected function imageGenerationCarrierModel(): string
    {
        return 'gpt-5.4-nano';
    }

    /**
     * Map `generateImage()`'s legacy Images API aspect (`'1:1'`, `'2:3'`,
     * `'3:2'`, or `null`) to the `image_generation` tool's own vocabulary:
     * `ImageGeneration` (GI-A1) only accepts `'square'`, `'vertical'` and
     * `'horizontal'`. `'1:1'`, an unrecognized value, and no size at all
     * all fall back to `'square'`.
     */
    protected function imageGenerationAspectFromSize(?string $size): string
    {
        return match ($size) {
            '2:3' => 'vertical',
            '3:2' => 'horizontal',
            default => 'square',
        };
    }

    /**
     * Extract the generated image from a Responses API `output` array.
     *
     * Looks for the `image_generation_call` item that the `image_generation`
     * provider tool (GI-A1) produces. A `message` item sometimes travels
     * alongside it with assistant text about the image (see the spike's
     * response_raw.json, where it is an empty `output_text`, and OpenAI's
     * docs show it can be non-empty). That text is discarded on purpose:
     * `ImageResponse`/`GeneratedImage` have no field for accompanying text,
     * so there is nowhere to carry it, and this comment exists so the
     * silence reads as a decision, not as a bug someone finds later.
     *
     * Only parsing lives here (GI-A2): wiring this into
     * `OpenAiGateway::generateImage()` is GI-A3.
     *
     * @param  array<int, array<string, mixed>>  $output
     *
     * @throws ImageGenerationFailedException
     */
    protected function extractGeneratedImageFromResponse(array $output): GeneratedImage
    {
        $call = collect($output)->firstWhere('type', 'image_generation_call');

        if (! $call) {
            throw ImageGenerationFailedException::forMissingCall();
        }

        if (($call['status'] ?? '') !== 'completed') {
            throw ImageGenerationFailedException::forStatus(
                (string) ($call['status'] ?? ''),
                $call['error']['message'] ?? null,
            );
        }

        return new GeneratedImage(
            $call['result'] ?? '',
            $this->imageMimeTypeFromOutputFormat($call['output_format'] ?? null),
        );
    }

    /**
     * Map the `output_format` the Responses API reports for the image
     * (`png`/`jpeg`/`webp`) to a MIME type.
     *
     * This is deliberately not a fixed `'image/png'` the way
     * `OpenAiGateway::generateImage()` builds its `GeneratedImage` for the
     * classic Images API today: the Responses API tool can return any of
     * the three formats, and the spike payloads confirm `output_format` is
     * always present on a completed call, so it is the source of truth
     * instead of an assumption.
     */
    protected function imageMimeTypeFromOutputFormat(?string $outputFormat): ?string
    {
        return match ($outputFormat) {
            'png' => 'image/png',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => null,
        };
    }
}

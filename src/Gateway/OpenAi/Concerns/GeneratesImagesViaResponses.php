<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\SupportsImageGeneration;
use Laravel\Ai\Exceptions\ImageGenerationFailedException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Providers\Tools\ImageGeneration;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;
use RuntimeException;

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
        ?string $quality,
        ?int $timeout,
    ): ImageResponse {
        if (! $provider instanceof SupportsImageGeneration) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support image generation.');
        }

        $data = $this->client($provider, $timeout ?? 120)->post('responses', [
            'model' => $provider->imageGenerationCarrierModel(),
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt],
                    ...$this->mapAttachments(collect($attachments), $provider),
                ],
            ]],
            'tools' => $this->mapTools(
                [new ImageGeneration($model, $this->imageGenerationAspectFromSize($size), $quality)],
                $provider,
            ),
        ])->json();

        return new ImageResponse(
            collect([$this->extractGeneratedImageFromResponse($data['output'] ?? [])]),
            $this->extractImageUsage($data, viaResponses: true),
            new Meta($provider->name(), $model, carrierModel: $provider->imageGenerationCarrierModel()),
        );
    }

    /**
     * Extract usage from a Responses API response for the `image_generation`
     * tool path (GI-A5). The two models billed by one call
     * (`imageGenerationCarrierModel()`, GI-A4) report their usage in two
     * sibling fields of the same payload:
     *
     * - The carrier model's own usage is the top-level `usage` field, in
     *   exactly the shape any other Responses API call reports it, so it is
     *   read with `ParsesTextResponses::extractUsage()` (mixed into
     *   `OpenAiGateway` alongside this trait) instead of a second
     *   implementation of the same parsing.
     * - The image tool's own usage is `tool_usage.image_gen`, kept in
     *   `Usage::$toolsTokens` rather than merged into the carrier's
     *   `inputTokens`/`outputTokens` buckets: the two are billed at
     *   different rates by two different models (`Meta::$model` is the
     *   image model, `Meta::$carrierModel` the carrier -- both set by the
     *   caller), and collapsing them into one bucket would make it
     *   impossible for a consumer (`CostHelper` in ai-ai, GI-B4) to price
     *   each tramo at its own model's rate. `output_tokens_details.image_tokens`
     *   is the tool's image output; `input_tokens_details.text_tokens` is
     *   the tool's own text input -- not the carrier's, which is already
     *   accounted for by `extractUsage()` above. Both directions are added
     *   per modality (there is no observed case, across the 21 real calls
     *   behind this branch, of a modality appearing on both sides at once).
     *
     * A response missing `tool_usage.image_gen` entirely is not a shape
     * OpenAI documents or that the spike ever produced: treating it as zero
     * image tokens would silently under-bill a completed generation, so
     * this throws instead of defaulting to 0 -- the gap has to reach ai-ai
     * as a failure it can see, not a suspiciously cheap image.
     *
     * @throws ImageGenerationFailedException
     */
    protected function extractResponsesImageUsage(array $data): Usage
    {
        $toolUsage = $data['tool_usage']['image_gen'] ?? null;

        if ($toolUsage === null) {
            throw ImageGenerationFailedException::forMissingToolUsage();
        }

        return $this->extractUsage($data)->add(new Usage(
            toolsTokens: [
                'text' => ($toolUsage['input_tokens_details']['text_tokens'] ?? 0)
                    + ($toolUsage['output_tokens_details']['text_tokens'] ?? 0),
                'image' => ($toolUsage['input_tokens_details']['image_tokens'] ?? 0)
                    + ($toolUsage['output_tokens_details']['image_tokens'] ?? 0),
            ],
        ));
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

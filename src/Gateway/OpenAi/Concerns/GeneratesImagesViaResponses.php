<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Laravel\Ai\Exceptions\ImageGenerationFailedException;
use Laravel\Ai\Responses\Data\GeneratedImage;

trait GeneratesImagesViaResponses
{
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

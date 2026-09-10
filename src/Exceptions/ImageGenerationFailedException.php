<?php

namespace Laravel\Ai\Exceptions;

class ImageGenerationFailedException extends AiException
{
    /**
     * Create a new exception for an `image_generation_call` output item whose
     * `status` is anything other than `completed` (e.g. `failed`, `in_progress`).
     */
    public static function forStatus(string $status, ?string $error = null): self
    {
        return new self(sprintf(
            'Image generation did not complete: status [%s]%s.',
            $status !== '' ? $status : 'unknown',
            $error ? ', '.$error : '',
        ));
    }

    /**
     * Create a new exception for a Responses API output that does not contain
     * an `image_generation_call` item at all.
     */
    public static function forMissingCall(): self
    {
        return new self('The Responses API output does not contain an [image_generation_call] item.');
    }

    /**
     * Create a new exception for a Responses API response that completed an
     * `image_generation_call` but carries no `tool_usage.image_gen` field
     * (GI-A5). Every response observed for this path has the field, so its
     * absence means the response is degraded in some way OpenAI does not
     * otherwise report -- treating it as zero image tokens would silently
     * under-bill an image that was, per the completed call, actually
     * generated. Raised instead so ai-ai sees a diagnosable failure rather
     * than a $0 cost.
     */
    public static function forMissingToolUsage(): self
    {
        return new self('The Responses API response does not contain a [tool_usage.image_gen] field required to account for the image generation cost.');
    }
}

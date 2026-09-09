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
}

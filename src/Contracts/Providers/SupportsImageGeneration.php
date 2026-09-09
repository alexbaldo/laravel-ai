<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Providers\Tools\ImageGeneration;

interface SupportsImageGeneration
{
    /**
     * Get the image generation tool options for the provider.
     */
    public function imageGenerationToolOptions(ImageGeneration $generation): array;

    /**
     * Get the name of the text model that carries the `image_generation`
     * tool call through the Responses API.
     */
    public function imageGenerationCarrierModel(): string;
}

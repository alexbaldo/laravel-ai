<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Providers\Tools\ImageGeneration;

interface SupportsImageGeneration
{
    /**
     * Get the image generation tool options for the provider.
     */
    public function imageGenerationToolOptions(ImageGeneration $generation): array;
}

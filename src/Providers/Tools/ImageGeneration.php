<?php

namespace Laravel\Ai\Providers\Tools;

class ImageGeneration extends ProviderTool
{
    /**
     * Create a new image generation tool instance.
     *
     * `$size` is a provider-agnostic aspect hint, not a pixel value: each
     * provider's `SupportsImageGeneration::imageGenerationToolOptions()`
     * translates it into its own vocabulary (OpenAI wants pixel dimensions
     * in `size`; Gemini's own image tooling instead uses an `aspectRatio`
     * string like `"9:16"` plus a separate `image_size` resolution tier --
     * see `GeminiProvider::defaultImageOptions()`). Only OpenAI implements
     * the contract today.
     *
     * Only the three ratios the AInara frontend actually sends today are
     * supported. `GenerateImage::size()` (in `ai-ai`) exposes 14 aspect
     * ratios in total; mapping the rest is intentionally out of scope until
     * a caller needs them.
     *
     * `quality` is an optional caller-supplied override, mirroring `model`
     * and `size`: when omitted, this package imposes no default of its own
     * -- OpenAI's mapping simply leaves the key out, letting the API apply
     * its own default. Picking a concrete quality in that case is a
     * business decision for the consuming application to make explicitly.
     *
     * @param  'square'|'vertical'|'horizontal'  $size
     * @param  'low'|'medium'|'high'|null  $quality
     */
    public function __construct(
        public string $model,
        public string $size,
        public ?string $quality = null,
    ) {}
}

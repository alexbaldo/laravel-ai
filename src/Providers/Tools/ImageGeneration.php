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
     * `quality` is deliberately not a constructor argument: it is not yet
     * exposed as caller-configurable, and each provider's mapping fixes it
     * to the lowest tier its active model supports.
     *
     * @param  'square'|'vertical'|'horizontal'  $size
     */
    public function __construct(
        public string $model,
        public string $size,
    ) {}
}

<?php

namespace Laravel\Ai\Responses\Data;

readonly class ImageUsage extends TextUsage
{
    /**
     * @param  int|null  $imageInputTokens  Subset of the input tokens billed for images, or null when unreported.
     * @param  int|null  $imageOutputTokens  Subset of the output tokens billed for generated images, or null when unreported.
     */
    public function __construct(
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?int $cacheReadInputTokens = null,
        ?int $cacheWriteInputTokens = null,
        ?int $reasoningTokens = null,
        public ?int $imageInputTokens = null,
        public ?int $imageOutputTokens = null,
        array $modalities = [],
    ) {
        parent::__construct($inputTokens, $outputTokens, $cacheReadInputTokens, $cacheWriteInputTokens, $reasoningTokens, $modalities);
    }

    /**
     * Determine if the usage carries more than a text-only split of its totals.
     */
    protected function reportsModalities(): bool
    {
        return parent::reportsModalities() || $this->imageInputTokens !== null || $this->imageOutputTokens !== null;
    }

    /**
     * Derive a per-modality split from the totals, attributing the image subsets to the image modality.
     *
     * @return array<string, array<string, int>>
     */
    protected function derivedModalities(): array
    {
        $derived = parent::derivedModalities();

        $derived['input'] = ['text' => $derived['input']['text'] - ($this->imageInputTokens ?? 0), 'image' => $this->imageInputTokens ?? 0];
        $derived['output'] = ['text' => $derived['output']['text'] - ($this->imageOutputTokens ?? 0), 'image' => $this->imageOutputTokens ?? 0];

        return $derived;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'image_input_tokens' => $this->imageInputTokens,
            'image_output_tokens' => $this->imageOutputTokens,
        ];
    }
}

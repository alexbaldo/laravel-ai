<?php

namespace Laravel\Ai\Responses\Data;

readonly class TextUsage extends Usage
{
    /**
     * @param  int  $inputTokens  Total input tokens, including any cached or cache-written tokens.
     * @param  int  $outputTokens  Total output tokens, including any reasoning tokens.
     * @param  int|null  $cacheReadInputTokens  Subset of the input tokens read from a prompt cache, or null when unreported.
     * @param  int|null  $cacheWriteInputTokens  Subset of the input tokens written to a prompt cache, or null when unreported.
     * @param  int|null  $reasoningTokens  Subset of the output tokens spent on reasoning, or null when unreported.
     * @param  array<string, array<string, int>>  $modalities  Per-modality split, keyed by input (uncached), output (excluding reasoning), cached (cache reads) and tools (a model run by a provider tool, billed apart).
     */
    public function __construct(
        int $inputTokens = 0,
        int $outputTokens = 0,
        public ?int $cacheReadInputTokens = null,
        public ?int $cacheWriteInputTokens = null,
        public ?int $reasoningTokens = null,
        public array $modalities = [],
    ) {
        parent::__construct($inputTokens, $outputTokens);
    }

    /**
     * Reconstruct an instance from a previously serialized toArray() payload.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            inputTokens: $data['input_tokens'] ?? 0,
            outputTokens: $data['output_tokens'] ?? 0,
            cacheReadInputTokens: $data['cache_read_input_tokens'] ?? null,
            cacheWriteInputTokens: $data['cache_write_input_tokens'] ?? null,
            reasoningTokens: $data['reasoning_tokens'] ?? null,
            modalities: $data['modalities'] ?? [],
        );
    }

    /**
     * Get the input tokens that were neither read from nor written to a prompt cache.
     */
    public function uncachedInputTokens(): int
    {
        return $this->inputTokens - ($this->cacheReadInputTokens ?? 0) - ($this->cacheWriteInputTokens ?? 0);
    }

    /**
     * Add the given usage to the current usage and return a new usage instance.
     */
    public function add(TextUsage $usage): TextUsage
    {
        return new TextUsage(
            $this->inputTokens + $usage->inputTokens,
            $this->outputTokens + $usage->outputTokens,
            static::sum($this->cacheReadInputTokens, $usage->cacheReadInputTokens),
            static::sum($this->cacheWriteInputTokens, $usage->cacheWriteInputTokens),
            static::sum($this->reasoningTokens, $usage->reasoningTokens),
            $this->modalities === [] && $usage->modalities === []
                ? []
                : static::sumModalities($this->resolvedModalities(), $usage->resolvedModalities()),
        );
    }

    /**
     * Get the per-modality split, derived from the totals when the provider did not report one.
     *
     * @return array<string, array<string, int>>
     */
    public function resolvedModalities(): array
    {
        return $this->modalities !== [] ? $this->modalities : $this->derivedModalities();
    }

    /**
     * Derive a per-modality split from the totals, attributing everything to text.
     *
     * @return array<string, array<string, int>>
     */
    protected function derivedModalities(): array
    {
        return [
            'input' => ['text' => $this->uncachedInputTokens()],
            'output' => ['text' => $this->outputTokens - ($this->reasoningTokens ?? 0)],
            'cached' => ['text' => $this->cacheReadInputTokens ?? 0],
        ];
    }

    /**
     * Get the token counts keyed by billing bucket and modality, so each can be priced at its own rate.
     *
     * @return array<string, array<string, int>>
     */
    public function breakdown(): array
    {
        $modalities = $this->resolvedModalities();

        return array_filter(array_map(fn (array $counts) => array_filter($counts), [
            'input_tokens' => $modalities['input'] ?? [],
            'output_tokens' => [...($modalities['output'] ?? []), 'reasoning' => $this->reasoningTokens ?? 0],
            'cached_tokens' => [...($modalities['cached'] ?? []), 'write' => $this->cacheWriteInputTokens ?? 0],
            'tools_tokens' => $modalities['tools'] ?? [],
        ]));
    }

    /**
     * Sum two per-modality splits bucket by bucket.
     *
     * @param  array<string, array<string, int>>  $a
     * @param  array<string, array<string, int>>  $b
     * @return array<string, array<string, int>>
     */
    protected static function sumModalities(array $a, array $b): array
    {
        foreach ($b as $bucket => $counts) {
            foreach ($counts as $modality => $tokens) {
                $a[$bucket][$modality] = ($a[$bucket][$modality] ?? 0) + $tokens;
            }
        }

        return $a;
    }

    /**
     * Sum two optional counts, preserving null when neither was reported.
     */
    protected static function sum(?int $a, ?int $b): ?int
    {
        return $a === null && $b === null ? null : ($a ?? 0) + ($b ?? 0);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            ...($this->modalities !== [] ? ['modalities' => $this->modalities] : []),
        ];
    }
}

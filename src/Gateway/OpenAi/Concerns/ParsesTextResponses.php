<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage;

trait ParsesTextResponses
{
    use DecodesStructuredOutput;

    /**
     * Validate the OpenAI response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'OpenAI Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown OpenAI error.',
            ));
        }

        if (($data['status'] ?? '') === 'failed') {
            $error = $data['error'] ?? [];

            throw new AiException(sprintf(
                'OpenAI Error: [%s] %s',
                $error['code'] ?? 'unknown',
                $error['message'] ?? 'The response failed without an error message.',
            ));
        }
    }

    /**
     * Parse the OpenAI response data into a single step response.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): StepResponse {
        $output = $data['output'] ?? [];
        $text = $this->extractText($output);

        return new StepResponse(
            text: $text,
            toolCalls: $this->mapToolCallsWithReasoning($output),
            finishReason: $this->extractFinishReason($data),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $data['model'] ?? '', $this->extractCitations($output)),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            continuationToken: $data['id'] ?? '',
            providerContentBlocks: $this->isStateless($provider) ? $this->extractReplayBlocks($output) : [],
        );
    }

    /**
     * Serialize a tool result output value to a string.
     */
    protected function serializeToolResultOutput(mixed $output): string
    {
        return match (true) {
            is_string($output) => $output,
            is_array($output) => (string) json_encode($output),
            default => (string) $output,
        };
    }

    /**
     * Extract the text content from the output array.
     */
    protected function extractText(array $output): string
    {
        $lastOutput = last($output);

        return is_array($lastOutput) ? ($lastOutput['content'][0]['text'] ?? '') : '';
    }

    /**
     * Extract citations from the output array.
     */
    protected function extractCitations(array $output): Collection
    {
        $citations = new Collection;

        foreach ($output as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                foreach ($content['annotations'] ?? [] as $annotation) {
                    if (($annotation['type'] ?? '') !== 'url_citation') {
                        continue;
                    }

                    $citations->push(new UrlCitation(
                        $annotation['url'] ?? '',
                        $annotation['title'] ?? null,
                        isset($annotation['start_index']) ? (int) $annotation['start_index'] : null,
                        isset($annotation['end_index']) ? (int) $annotation['end_index'] : null,
                    ));
                }
            }
        }

        return $citations->values();
    }

    /**
     * Extract the ordered response output for stateless (store=false) replay.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractReplayBlocks(array $output): array
    {
        return array_values(array_filter($output, 'is_array'));
    }

    /**
     * Extract usage data from the response.
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];
        $details = $usage['input_tokens_details'] ?? [];
        $cachedTokens = $details['cached_tokens'] ?? 0;
        $cacheWriteTokens = $details['cache_write_tokens'] ?? 0;

        // Cached and cache-write tokens are billed at their own rates, so they do not
        // belong in the plain input count. They are text tokens, which is why only the
        // text bucket is corrected and the image and audio ones are taken as reported.
        $textTokens = $details['text_tokens'] ?? $usage['input_tokens'] ?? 0;

        return new Usage(
            inputTokens: [
                'text' => max($textTokens - $cachedTokens - $cacheWriteTokens, 0),
                'image' => $details['image_tokens'] ?? 0,
                'audio' => $details['audio_tokens'] ?? 0,
            ],
            outputTokens: [
                'text' => $usage['output_tokens_details']['text_tokens'] ?? $usage['output_tokens'] ?? 0,
                'image' => $usage['output_tokens_details']['image_tokens'] ?? 0,
                'audio' => $usage['output_tokens_details']['audio_tokens'] ?? 0,
                'reasoning' => $usage['output_tokens_details']['reasoning_tokens'] ?? 0,
            ],
            cachedTokens: [
                'text' => $details['cached_tokens_details']['text_tokens'] ?? $cachedTokens,
                'image' => $details['cached_tokens_details']['image_tokens'] ?? 0,
                'audio' => $details['cached_tokens_details']['audio_tokens'] ?? 0,
            ],
            cacheWriteInputTokens: $cacheWriteTokens,
        );
    }

    /**
     * Extract and map the finish reason from the response.
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        $lastOutput = last($data['output'] ?? []);
        $status = $lastOutput['status'] ?? $data['status'] ?? '';
        $type = $lastOutput['type'] ?? '';

        return match ($status) {
            'incomplete' => FinishReason::Length,
            'failed' => FinishReason::Error,
            'completed' => match ($type) {
                'function_call' => FinishReason::ToolCalls,
                'message' => FinishReason::Stop,
                default => str_ends_with((string) $type, '_call') ? FinishReason::ToolCalls : FinishReason::Unknown,
            },
            default => FinishReason::Unknown,
        };
    }

    /**
     * Map tool calls with their associated reasoning blocks.
     *
     * @return array<ToolCall>
     */
    protected function mapToolCallsWithReasoning(array $output): array
    {
        $toolCalls = [];
        $latestReasoning = null;

        foreach ($output as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'reasoning') {
                $latestReasoning = $item;

                continue;
            }

            if ($type === 'function_call') {
                $toolCalls[] = new ToolCall(
                    $item['id'] ?? '',
                    $item['name'] ?? '',
                    json_decode($item['arguments'] ?? '{}', true) ?? [],
                    $item['call_id'] ?? null,
                    $latestReasoning ? ($latestReasoning['id'] ?? null) : null,
                    $latestReasoning ? ($latestReasoning['summary'] ?? null) : null,
                    $latestReasoning ? ($latestReasoning['encrypted_content'] ?? null) : null,
                );
            }
        }

        return $toolCalls;
    }
}

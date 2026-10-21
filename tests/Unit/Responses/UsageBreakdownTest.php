<?php

use Laravel\Ai\Gateway\Gemini\Concerns\ParsesTextResponses as ParsesGeminiResponses;
use Laravel\Ai\Gateway\OpenAi\Concerns\ParsesTextResponses as ParsesOpenAiResponses;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\Usage;

function extractUsageWith(string $trait, array $data): TextUsage
{
    $parser = match ($trait) {
        'gemini' => new class
        {
            use ParsesGeminiResponses;
        },
        'openai' => new class
        {
            use ParsesOpenAiResponses;
        },
    };

    return (fn () => $this->extractUsage($data))->call($parser);
}

test('text usage without modalities is broken down from its totals', function () {
    $usage = new TextUsage(100, 50, cacheReadInputTokens: 30, cacheWriteInputTokens: 10, reasoningTokens: 20);

    expect($usage->breakdown())->toBe([
        'input_tokens' => ['text' => 60],
        'output_tokens' => ['text' => 30, 'reasoning' => 20],
        'cached_tokens' => ['text' => 30, 'write' => 10],
    ]);
});

test('plain usage is broken down as text', function () {
    expect((new Usage(12, 0))->breakdown())->toBe(['input_tokens' => ['text' => 12]]);
});

test('image usage without modalities attributes its image subsets to the image modality', function () {
    $usage = new ImageUsage(100, 1100, imageInputTokens: 40, imageOutputTokens: 1000);

    expect($usage->breakdown())->toBe([
        'input_tokens' => ['text' => 60, 'image' => 40],
        'output_tokens' => ['text' => 100, 'image' => 1000],
    ]);
});

test('adding usages sums their modalities, deriving them for a side that reported none', function () {
    $withModalities = new TextUsage(10, 5, modalities: [
        'input' => ['text' => 4, 'audio' => 6],
        'output' => ['text' => 5],
    ]);

    $sum = $withModalities->add(new TextUsage(3, 2));

    expect($sum->inputTokens)->toBe(13)
        ->and($sum->breakdown())->toBe([
            'input_tokens' => ['text' => 7, 'audio' => 6],
            'output_tokens' => ['text' => 7],
        ]);
});

test('adding usages without modalities keeps them empty', function () {
    expect((new TextUsage(1, 1))->add(new TextUsage(2, 2))->modalities)->toBe([]);
});

test('modalities survive a serialization round trip', function () {
    $usage = new TextUsage(10, 5, modalities: ['input' => ['audio' => 10], 'output' => ['text' => 5]]);

    expect(TextUsage::fromArray($usage->toArray())->modalities)->toBe($usage->modalities);
});

test('gemini usage is split by modality, net of cache reads, with tool use billed as input', function () {
    $usage = extractUsageWith('gemini', ['usage' => [
        'total_input_tokens' => 1000,
        'total_output_tokens' => 40,
        'total_cached_tokens' => 200,
        'total_thought_tokens' => 60,
        'total_tool_use_tokens' => 15,
        'input_tokens_by_modality' => [
            ['modality' => 'text', 'tokens' => 300],
            ['modality' => 'audio', 'tokens' => 700],
        ],
        'cached_tokens_by_modality' => [
            ['modality' => 'text', 'tokens' => 200],
        ],
        'tool_use_tokens_by_modality' => [
            ['modality' => 'text', 'tokens' => 15],
        ],
        'output_tokens_by_modality' => [
            ['modality' => 'text', 'tokens' => 40],
        ],
    ]]);

    expect($usage->inputTokens)->toBe(1015)
        ->and($usage->outputTokens)->toBe(100)
        ->and($usage->breakdown())->toBe([
            'input_tokens' => ['text' => 115, 'audio' => 700],
            'output_tokens' => ['text' => 40, 'reasoning' => 60],
            'cached_tokens' => ['text' => 200],
        ]);
});

test('gemini usage without a modality breakdown is attributed to text', function () {
    $usage = extractUsageWith('gemini', ['usage' => [
        'total_input_tokens' => 50,
        'total_output_tokens' => 10,
    ]]);

    expect($usage->breakdown())->toBe([
        'input_tokens' => ['text' => 50],
        'output_tokens' => ['text' => 10],
    ]);
});

test('openai usage is split by modality, net of cache reads and without reasoning in the output text', function () {
    $usage = extractUsageWith('openai', ['usage' => [
        'input_tokens' => 1000,
        'output_tokens' => 300,
        'input_tokens_details' => [
            'cached_tokens' => 500,
            'text_tokens' => 800,
            'image_tokens' => 200,
            'cached_tokens_details' => ['text_tokens' => 400, 'image_tokens' => 100],
        ],
        'output_tokens_details' => ['reasoning_tokens' => 120],
    ]]);

    expect($usage->breakdown())->toBe([
        'input_tokens' => ['text' => 400, 'image' => 100],
        'output_tokens' => ['text' => 180, 'reasoning' => 120],
        'cached_tokens' => ['text' => 400, 'image' => 100],
    ]);
});

test('openai transcription usage reads its singular input token details key', function () {
    $usage = extractUsageWith('openai', ['usage' => [
        'input_tokens' => 120,
        'output_tokens' => 30,
        'input_token_details' => ['text_tokens' => 20, 'audio_tokens' => 100],
    ]]);

    expect($usage->breakdown())->toBe([
        'input_tokens' => ['text' => 20, 'audio' => 100],
        'output_tokens' => ['text' => 30],
    ]);
});

<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ImageGenerationFailedException;
use Laravel\Ai\Image;

/**
 * GI-A5: `Usage`/`Meta` for the Responses API `image_generation` tool path
 * (`GeneratesImagesViaResponses::generateImageViaResponses()`, GI-A3). The
 * dispatch itself is covered by `ImageGenerationResponsesDispatchTest.php`;
 * this file covers what `extractImageUsage()`'s new branch does with the
 * two token sources a Responses API call reports for this tool -- the
 * carrier model's own `usage` and the image tool's `tool_usage.image_gen`
 * -- and the classic Images API branch it must leave untouched (R2).
 */
beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function makeUploadedDocxFileForUsageTest(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'ai').'.docx';
    file_put_contents($path, 'docx-bytes');

    return new UploadedFile(
        $path,
        'brief.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        null,
        true,
    );
}

/**
 * Builds a fake `responses` payload shaped like the real capture in
 * docs/team/spikes/2026-09-09-image-generation-responses-api/response_raw.json,
 * with the carrier model's `usage` and the image tool's
 * `tool_usage.image_gen` as two independent, overridable fields so tests can
 * drop either one without touching the other.
 */
function fakeOpenAiResponsesUsagePayload(?array $usage, ?array $toolUsageImageGen): array
{
    return array_filter([
        'output' => [[
            'type' => 'image_generation_call',
            'status' => 'completed',
            'output_format' => 'png',
            'result' => base64_encode('doc-generated-image'),
        ]],
        'usage' => $usage,
        'tool_usage' => $toolUsageImageGen === null ? null : ['image_gen' => $toolUsageImageGen, 'web_search' => ['num_requests' => 0]],
    ], fn ($value): bool => $value !== null);
}

function fakeOpenAiResponsesUsageResponse(?array $usage, ?array $toolUsageImageGen): PromiseInterface
{
    return Http::response(fakeOpenAiResponsesUsagePayload($usage, $toolUsageImageGen));
}

// The real figures from response_raw.json (§3.5/§4.3 of the plan): the
// carrier (gpt-5.4-nano) read the document and decided to call the tool
// (2535 input / 185 output, plain text usage, no tool_usage.* fields at
// all); the image_gen tool then spent 172 input tokens (all text -- its
// own derived prompt) and 1372 output tokens (all image -- the picture
// itself) drawing with gpt-image-2.
const SPIKE_CARRIER_USAGE = [
    'input_tokens' => 2535,
    'output_tokens' => 185,
    'output_tokens_details' => ['reasoning_tokens' => 0],
];

const SPIKE_IMAGE_GEN_TOOL_USAGE = [
    'input_tokens' => 172,
    'input_tokens_details' => ['image_tokens' => 0, 'text_tokens' => 172],
    'output_tokens' => 1372,
    'output_tokens_details' => ['image_tokens' => 1372, 'text_tokens' => 0],
    'total_tokens' => 1544,
];

test('usage via the responses path keeps the carrier and image tool tokens in separate buckets', function (): void {
    Http::fake(['*' => fakeOpenAiResponsesUsageResponse(SPIKE_CARRIER_USAGE, SPIKE_IMAGE_GEN_TOOL_USAGE)]);

    $file = makeUploadedDocxFileForUsageTest();

    $response = Image::of('Illustrate this document')
        ->attachments([$file])
        ->generate(provider: 'openai', model: 'gpt-image-2.5-flare');

    unlink($file->getPathname());

    // The carrier's own tokens (2535 in / 185 out) land in the usual
    // input/output buckets, exactly as any other Responses API call would
    // report them -- promptTokens()/completionTokens() read only those.
    expect($response->usage->promptTokens)->toBe(2535)
        ->and($response->usage->completionTokens)->toBe(185)
        // The image tool's own tokens (172 in / 1372 out) never touch those
        // buckets: they live in toolsTokens, split by modality exactly as
        // the plan's mapping requires -- output_tokens_details.image_tokens
        // is the image tokens, input_tokens_details.text_tokens is the
        // tool's own (not the carrier's) text tokens.
        ->and($response->usage->toolsTokens)->toBe(['text' => 172, 'image' => 1372]);
});

test('usage via the responses path does not fold the image tool tokens into the carrier prompt/completion totals', function (): void {
    Http::fake(['*' => fakeOpenAiResponsesUsageResponse(SPIKE_CARRIER_USAGE, SPIKE_IMAGE_GEN_TOOL_USAGE)]);

    $file = makeUploadedDocxFileForUsageTest();

    $response = Image::of('Illustrate this document')
        ->attachments([$file])
        ->generate(provider: 'openai', model: 'gpt-image-2.5-flare');

    unlink($file->getPathname());

    // If the 1372 image-output tokens leaked into completionTokens() (which
    // only sums inputTokens/outputTokens, not toolsTokens), this would read
    // 1557, not 185 -- a silent conflation between two models billed at two
    // different rates, exactly what the separate toolsTokens bucket exists
    // to prevent (CostHelper needs the two tramos apart, ai-ai GI-B4).
    expect($response->usage->completionTokens)->toBe(185);
});

test('a responses payload missing tool_usage.image_gen throws instead of silently costing zero', function (): void {
    Http::fake(['*' => fakeOpenAiResponsesUsageResponse(SPIKE_CARRIER_USAGE, null)]);

    $file = makeUploadedDocxFileForUsageTest();

    Image::of('Illustrate this document')
        ->attachments([$file])
        ->generate(provider: 'openai', model: 'gpt-image-2.5-flare');

    unlink($file->getPathname());
})->throws(ImageGenerationFailedException::class, 'tool_usage.image_gen');

test('meta from the responses path exposes both the image model and the carrier model that generated it', function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
        'models' => ['image' => ['carrier' => 'gpt-5.9-configured']],
    ]]);

    Http::fake(['*' => fakeOpenAiResponsesUsageResponse(SPIKE_CARRIER_USAGE, SPIKE_IMAGE_GEN_TOOL_USAGE)]);

    $file = makeUploadedDocxFileForUsageTest();

    $response = Image::of('Illustrate this document')
        ->attachments([$file])
        ->generate(provider: 'openai', model: 'gpt-image-2.5-flare');

    unlink($file->getPathname());

    expect($response->meta->model)->toBe('gpt-image-2.5-flare')
        ->and($response->meta->carrierModel)->toBe('gpt-5.9-configured')
        // The two are genuinely different models, which is the entire point:
        // CostHelper (ai-ai, GI-B4) prices each at its own rate.
        ->and($response->meta->model)->not->toBe($response->meta->carrierModel);
});

// R2 regression: extractImageUsage()'s new $viaResponses branch (GI-A5) must
// not change what the classic Images API branch computes -- it is reached
// only when generateImageViaResponses() explicitly opts in, and the images
// API response shape (a flat `usage` object, no `tool_usage` sibling at all)
// never triggers it in the first place. ImageGenerationTest.php/ImageEditTest.php
// already exercise this branch extensively; this test exists to make the
// "no tool_usage.* leaks into toolsTokens on the classic path" property
// explicit, next to the new branch it is guarding against.
test('usage via the classic images API leaves toolsTokens empty, unaffected by the new responses branch', function (): void {
    Http::fake(['*' => Http::response([
        'data' => [['b64_json' => base64_encode('fresh-image')]],
        'usage' => [
            'input_tokens' => 41,
            'output_tokens' => 1024,
            'input_tokens_details' => ['text_tokens' => 41, 'image_tokens' => 0],
        ],
    ])]);

    $response = Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');

    expect($response->usage->promptTokens)->toBe(41)
        ->and($response->usage->completionTokens)->toBe(1024)
        ->and($response->usage->toolsTokens)->toBe([])
        ->and($response->meta->carrierModel)->toBeNull();
});

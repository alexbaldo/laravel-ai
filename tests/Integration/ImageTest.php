<?php

use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Image;

test('images can be generated', function (string $provider, string $apiKey, string $model): void {
    requiresApiKey($apiKey);

    $response = Image::of('Donut sitting on a kitchen counter.')
        ->generate(provider: $provider, model: $model);

    expect($response->meta->provider)->toEqual($provider);
})->with('image-providers');

test('images can be generated with square size', function (string $provider, string $apiKey, string $model): void {
    requiresApiKey($apiKey);

    $response = Image::of('Donut sitting on a kitchen counter.')
        ->square()
        ->generate(provider: $provider, model: $model);

    expect($response->meta->provider)->toEqual($provider);
})->with('image-providers');

// GI-Q4: R1 for the package -- a real generation from a non-image attachment
// (a `.docx`), proving `OpenAiGateway::generateImage()` really dispatches to
// the Responses API `image_generation` tool (GI-A3) end to end, not just
// against `Http::fake()` fixtures. OpenAI-only: the tool is specific to that
// provider (§4.5 of the plan), so this does not use the `image-providers`
// dataset. The fixture is the canary `.docx` from the 2026-09-09 spike that
// already proved (visually, against real content) that the model reads the
// document rather than inventing filler -- this test only needs to prove the
// call succeeds and produces a valid image, not re-verify that content.
test('an image can be generated from a non-image document attachment via the responses api image_generation tool', function (): void {
    requiresApiKey('OPENAI_API_KEY');

    $response = Image::of('Illustrate a scene inspired by the content of the attached document.')
        ->attachments([new LocalDocument(
            __DIR__.'/../Fixtures/Documents/verdalux.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        )])
        ->generate(provider: 'openai', model: 'gpt-image-2');

    $decodedImage = base64_decode($response->firstImage()->image, true);

    expect($response->meta->provider)->toEqual('openai')
        // Only set when the call went through the carrier-model Responses
        // API path (GI-A4/GI-A5) rather than the classic Images API.
        ->and($response->meta->carrierModel)->not->toBeNull()
        ->and($decodedImage)->not->toBeFalse()
        ->and(strlen($decodedImage))->toBeGreaterThan(0)
        // GI-A5: the image tool's own usage, read from `tool_usage.image_gen`,
        // not zeroed out or silently missing.
        ->and($response->usage->toolsTokens['image'] ?? 0)->toBeGreaterThan(0);
});

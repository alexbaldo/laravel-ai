<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\ImageResponse;

/**
 * GI-A3: `OpenAiGateway::generateImage()` dispatches to the Responses API
 * `image_generation` tool (GI-A1/GI-A2) instead of the classic Images API
 * when at least one attachment is not an image. These tests cover that
 * dispatch decision; `ImageGenerationTest`/`ImageEditTest` already cover
 * (and, per R2, must keep covering unchanged) the no-attachments and
 * image-only-attachments paths.
 */
beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenAiResponsesGenerationResponse(): PromiseInterface
{
    return Http::response([
        'output' => [[
            'type' => 'image_generation_call',
            'status' => 'completed',
            'output_format' => 'png',
            'result' => base64_encode('doc-generated-image'),
        ]],
    ]);
}

function makeUploadedDocxFile(): UploadedFile
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

test('a non-image uploaded file dispatches to the responses endpoint with the image_generation tool', function (): void {
    Http::fake(['*' => fakeOpenAiResponsesGenerationResponse()]);

    $file = makeUploadedDocxFile();

    $response = Image::of('Illustrate this document')
        ->attachments([$file])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    unlink($file->getPathname());

    expect($response)->toBeInstanceOf(ImageResponse::class)
        ->and($response->firstImage()->image)->toBe(base64_encode('doc-generated-image'))
        ->and($response->firstImage()->mime())->toBe('image/png');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return str_ends_with($request->url(), 'responses')
            && $body['tools'][0]['type'] === 'image_generation'
            && $body['tools'][0]['model'] === 'gpt-image-1'
            && $body['input'][0]['content'][0] === ['type' => 'input_text', 'text' => 'Illustrate this document']
            && $body['input'][0]['content'][1]['type'] === 'input_file';
    });

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), 'images/edits')
        || str_ends_with($request->url(), 'images/generations'));
});

test('mixing an image and a non-image attachment still dispatches to the responses endpoint and keeps both parts', function (): void {
    Http::fake(['*' => fakeOpenAiResponsesGenerationResponse()]);

    $file = makeUploadedDocxFile();

    Image::of('Illustrate this document, matching the reference style')
        ->attachments([
            new Base64Image(base64_encode('reference-bytes'), 'image/png'),
            $file,
        ])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    unlink($file->getPathname());

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $parts = $body['input'][0]['content'] ?? [];

        // Text part + one part per attachment: nothing lost in the mapping.
        return str_ends_with($request->url(), 'responses')
            && count($parts) === 3
            && $parts[1]['type'] === 'input_image'
            && $parts[2]['type'] === 'input_file';
    });
});

test('the vertical and horizontal aspect hints reach the image_generation tool, defaulting unknown sizes to square', function (string $size, string $expectedAspect): void {
    Http::fake(['*' => fakeOpenAiResponsesGenerationResponse()]);

    $file = makeUploadedDocxFile();

    Image::of('Illustrate this document')
        ->attachments([$file])
        ->when($size !== '', fn ($pending) => $pending->size($size))
        ->generate(provider: 'openai', model: 'gpt-image-1');

    unlink($file->getPathname());

    Http::assertSent(function (Request $request) use ($expectedAspect): bool {
        $body = json_decode($request->body(), true);

        return $body['tools'][0]['size'] === match ($expectedAspect) {
            'vertical' => '864x1536',
            'horizontal' => '1536x864',
            default => '1024x1024',
        };
    });
})->with([
    'no size at all' => ['', 'square'],
    '1:1' => ['1:1', 'square'],
    '2:3' => ['2:3', 'vertical'],
    '3:2' => ['3:2', 'horizontal'],
]);

test('an image-only attachment is unaffected by the new dispatch and still goes through images/edits', function (): void {
    Http::fake(['*' => Http::response([
        'data' => [['b64_json' => base64_encode('edited-image')]],
    ])]);

    Image::of('Make it brighter')
        ->attachments([new Base64Image(base64_encode('source-bytes'), 'image/png')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'images/edits'));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), 'responses'));
});

test('no attachments at all is unaffected by the new dispatch and still goes through images/generations', function (): void {
    Http::fake(['*' => Http::response([
        'data' => [['b64_json' => base64_encode('fresh-image')]],
    ])]);

    Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'images/generations'));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), 'responses'));
});

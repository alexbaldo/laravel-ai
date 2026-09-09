<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\ImageResponse;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenAiImageEditResponse(): PromiseInterface
{
    return Http::response([
        'data' => [[
            'b64_json' => base64_encode('edited-image'),
        ]],
    ]);
}

function fakeOpenAiResponsesImageGenerationResponse(): PromiseInterface
{
    return Http::response([
        'output' => [[
            'type' => 'image_generation_call',
            'status' => 'completed',
            'output_format' => 'png',
            'result' => base64_encode('generated-image'),
        ]],
    ]);
}

test('a base64 image is sent to the edits endpoint as the image content', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    Image::of('Make it brighter')
        ->attachments([new Base64Image(base64_encode('source-bytes'), 'image/png')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'images/edits')
        && $request->hasFile('image[]', 'source-bytes', 'image.png'));
});

test('a remote image is fetched and sent as the image content', function (): void {
    Http::fake([
        'example.com/*' => Http::response('remote-bytes'),
        '*' => fakeOpenAiImageEditResponse(),
    ]);

    Image::of('Make it brighter')
        ->attachments([new RemoteImage('https://example.com/source.png', 'image/png')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => $request->hasFile('image[]', 'remote-bytes'));
});

test('a local image is sent as the image content', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    $path = tempnam(sys_get_temp_dir(), 'ai').'.png';
    file_put_contents($path, 'local-bytes');

    Image::of('Make it brighter')
        ->attachments([new LocalImage($path, 'image/png')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => $request->hasFile('image[]', 'local-bytes'));

    unlink($path);
});

test('a stored image is sent as the image content', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    Storage::fake('images');
    Storage::disk('images')->put('source.png', 'stored-bytes');

    Image::of('Make it brighter')
        ->attachments([new StoredImage('source.png', 'images')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => $request->hasFile('image[]', 'stored-bytes'));
});

test('an uploaded file is sent as the image content', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    $path = tempnam(sys_get_temp_dir(), 'ai').'.png';
    file_put_contents($path, 'uploaded-bytes');

    Image::of('Make it brighter')
        ->attachments([new UploadedFile($path, 'source.png', 'image/png', null, true)])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => $request->hasFile('image[]', 'uploaded-bytes'));

    unlink($path);
});

test('a provider image cannot be used for edits', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    Image::of('Make it brighter')
        ->attachments([new ProviderImage('file-123')])
        ->generate(provider: 'openai', model: 'gpt-image-1');
})->throws(InvalidArgumentException::class, 'Unsupported image attachment type');

// Before GI-A3, a document attachment reached `sendImageEditRequest()`
// (the only path attachments could take) and its `match` had no case for
// anything but `Image`/`UploadedFile`, so it threw. GI-A3 gives non-image
// attachments a real path of their own -- the Responses API `image_generation`
// tool, which can read documents -- so this now succeeds instead. That is
// the AC (§6.1, GI-A3: "con adjuntos no-imagen ... va por la Responses API"),
// not a regression: R2 only protects the no-attachments/image-only calls,
// asserted unchanged by every other test in this file.
test('a document attachment is generated via the Responses API image_generation tool instead of edits', function (): void {
    Http::fake(['*' => fakeOpenAiResponsesImageGenerationResponse()]);

    $path = tempnam(sys_get_temp_dir(), 'ai').'.pdf';
    file_put_contents($path, 'document-bytes');

    $response = Image::of('Make it brighter')
        ->attachments([new LocalDocument($path, 'application/pdf')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    unlink($path);

    expect($response)->toBeInstanceOf(ImageResponse::class)
        ->and($response->firstImage()->image)->toBe(base64_encode('generated-image'));

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return str_ends_with($request->url(), 'responses')
            && ($body['tools'][0]['type'] ?? null) === 'image_generation';
    });

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), 'images/edits'));
});

test('non gpt-image models send a single image field', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    Image::of('Make it brighter')
        ->attachments([new Base64Image(base64_encode('source-bytes'), 'image/png')])
        ->generate(provider: 'openai', model: 'dall-e-2');

    Http::assertSent(fn (Request $request): bool => $request->hasFile('image', 'source-bytes', 'image.png'));
});

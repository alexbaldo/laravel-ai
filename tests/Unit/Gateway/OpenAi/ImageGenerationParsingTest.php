<?php

use Laravel\Ai\Exceptions\ImageGenerationFailedException;
use Laravel\Ai\Gateway\OpenAi\Concerns\GeneratesImagesViaResponses;
use Laravel\Ai\Responses\Data\GeneratedImage;

// Minimal valid 1x1 transparent PNG, base64-encoded, standing in for the
// real (much longer) `result` field the Responses API returns.
const SAMPLE_BASE64_IMAGE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

function imageGenerationResponseParser(): object
{
    return new class
    {
        use GeneratesImagesViaResponses;

        public function parse(array $output): GeneratedImage
        {
            return $this->extractGeneratedImageFromResponse($output);
        }
    };
}

function imageGenerationCallItem(array $overrides = []): array
{
    return [
        'type' => 'image_generation_call',
        'status' => 'completed',
        'output_format' => 'png',
        'result' => SAMPLE_BASE64_IMAGE,
        'revised_prompt' => 'A test image.',
        'size' => '1536x1024',
        'quality' => 'medium',
        ...$overrides,
    ];
}

test('extracts a GeneratedImage using the output_format the response reports, not a fixed image/png', function (): void {
    $image = imageGenerationResponseParser()->parse([
        imageGenerationCallItem(['output_format' => 'jpeg']),
    ]);

    expect($image)->toBeInstanceOf(GeneratedImage::class)
        ->and($image->image)->toBe(SAMPLE_BASE64_IMAGE)
        ->and($image->mime())->toBe('image/jpeg');
});

test('maps each output_format OpenAI documents to its MIME type', function (string $outputFormat, string $mime): void {
    $image = imageGenerationResponseParser()->parse([
        imageGenerationCallItem(['output_format' => $outputFormat]),
    ]);

    expect($image->mime())->toBe($mime);
})->with([
    'png' => ['png', 'image/png'],
    'jpeg' => ['jpeg', 'image/jpeg'],
    'webp' => ['webp', 'image/webp'],
]);

test('throws an identifiable error instead of an empty image when the call did not complete', function (string $status): void {
    expect(fn () => imageGenerationResponseParser()->parse([
        imageGenerationCallItem(['status' => $status, 'result' => null]),
    ]))->toThrow(ImageGenerationFailedException::class);
})->with([
    'failed' => ['failed'],
    'in_progress' => ['in_progress'],
]);

test('includes the item error message when a failed call reports one', function (): void {
    expect(fn () => imageGenerationResponseParser()->parse([
        imageGenerationCallItem([
            'status' => 'failed',
            'result' => null,
            'error' => ['message' => 'Image generation is not available with this model.'],
        ]),
    ]))->toThrow(ImageGenerationFailedException::class, 'Image generation is not available with this model.');
});

test('throws when the output has no image_generation_call item at all', function (): void {
    expect(fn () => imageGenerationResponseParser()->parse([
        [
            'type' => 'message',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => 'Nothing to see here.']],
        ],
    ]))->toThrow(ImageGenerationFailedException::class);
});

test('an accompanying output_text message does not break parsing and never reaches the GeneratedImage', function (): void {
    $image = imageGenerationResponseParser()->parse([
        imageGenerationCallItem(),
        [
            'type' => 'message',
            'status' => 'completed',
            'content' => [
                ['type' => 'output_text', 'annotations' => [], 'text' => 'Here is your illustration.'],
            ],
            'role' => 'assistant',
        ],
    ]);

    expect($image)->toBeInstanceOf(GeneratedImage::class)
        // GeneratedImage/ImageResponse have no field for accompanying text --
        // toArray() only ever carries the image and its mime, confirming the
        // discarded message text has nowhere it could have leaked into.
        ->and($image->toArray())->toBe([
            'image' => SAMPLE_BASE64_IMAGE,
            'mime' => 'image/png',
        ]);
});

<?php

use Laravel\Ai\Contracts\Providers\SupportsImageGeneration;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ImageGeneration;

function openAiImageGenerationMapper(): object
{
    return new class
    {
        use MapsTools;

        public function map(array $tools, Provider $provider, bool $stateless = false): array
        {
            return $this->mapTools($tools, $provider, $stateless);
        }
    };
}

function openAiProviderWithoutImageGeneration(): Provider
{
    return new class extends Provider
    {
        public function __construct()
        {
            //
        }

        public function name(): string
        {
            return 'openai';
        }
    };
}

function openAiProviderWithImageGeneration(): Provider
{
    return new class extends Provider implements SupportsImageGeneration
    {
        public function __construct()
        {
            //
        }

        public function name(): string
        {
            return 'openai';
        }

        public function imageGenerationToolOptions(ImageGeneration $generation): array
        {
            return [
                'model' => $generation->model,
                'size' => '1024x1024',
                'quality' => 'low',
            ];
        }
    };
}

test('throws when the provider does not implement SupportsImageGeneration', function () {
    expect(fn () => openAiImageGenerationMapper()->map(
        [new ImageGeneration(model: 'gpt-image-2.5-flare', size: 'square')],
        openAiProviderWithoutImageGeneration(),
    ))->toThrow(RuntimeException::class, 'does not support image generation');
});

test('emits an image_generation entry merged with the provider tool options', function () {
    $mapped = openAiImageGenerationMapper()->map(
        [new ImageGeneration(model: 'gpt-image-2.5-flare', size: 'square')],
        openAiProviderWithImageGeneration(),
    );

    expect($mapped)->toBe([[
        'type' => 'image_generation',
        'model' => 'gpt-image-2.5-flare',
        'size' => '1024x1024',
        'quality' => 'low',
    ]]);
});

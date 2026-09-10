<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;

class Meta implements Arrayable, JsonSerializable
{
    /** @var Collection<int, Citation> */
    public Collection $citations;

    /**
     * @param  Collection<int, Citation>|null  $citations
     * @param  string|null  $carrierModel  The text model that carried an
     *                                     `image_generation` tool call through the Responses API
     *                                     (`GeneratesImagesViaResponses`, GI-A4/GI-A5), when `$model`
     *                                     is the image model rather than the model that produced this
     *                                     response directly. `null` for every other response type,
     *                                     including a classic-Images-API-generated image, where a
     *                                     single model made the whole call and there is no second
     *                                     tramo of cost to attribute.
     */
    public function __construct(
        public ?string $provider = null,
        public ?string $model = null,
        ?Collection $citations = null,
        public ?string $carrierModel = null,
    ) {
        $this->citations = $citations ?? new Collection;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'citations' => $this->citations
                ? $this->citations->all()
                : [],
            'carrier_model' => $this->carrierModel,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}

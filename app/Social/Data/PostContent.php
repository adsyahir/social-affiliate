<?php

namespace App\Social\Data;

final readonly class PostContent
{
    /**
     * @param  list<string>  $imageUrls  Several photos make a carousel where the platform supports it.
     */
    public function __construct(
        public string $text,
        public ?string $imageUrl = null,
        public array $imageUrls = [],
    ) {}

    /**
     * @return list<string>
     */
    public function images(): array
    {
        return $this->imageUrls !== [] ? array_values($this->imageUrls) : array_values(array_filter([$this->imageUrl]));
    }
}

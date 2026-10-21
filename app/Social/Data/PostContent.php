<?php

namespace App\Social\Data;

final readonly class PostContent
{
    public function __construct(
        public string $text,
        public ?string $imageUrl = null,
    ) {}
}

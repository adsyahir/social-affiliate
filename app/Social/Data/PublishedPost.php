<?php

namespace App\Social\Data;

final readonly class PublishedPost
{
    public function __construct(
        public string $id,
        public ?string $permalink = null,
    ) {}
}

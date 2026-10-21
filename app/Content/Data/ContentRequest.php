<?php

namespace App\Content\Data;

use App\Models\Product;
use App\Models\SocialAccount;

final readonly class ContentRequest
{
    /**
     * @param  string|null  $feedback  What to change, from a person or from a failed rule check.
     * @param  array{post: string, link_reply: string}|null  $previous  The draft the feedback is about.
     */
    public function __construct(
        public Product $product,
        public SocialAccount $account,
        public ?string $feedback = null,
        public ?array $previous = null,
    ) {}
}

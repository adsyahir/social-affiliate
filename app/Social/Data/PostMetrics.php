<?php

namespace App\Social\Data;

/**
 * Lifetime numbers for one post, in the shape of the post_insights table.
 */
final readonly class PostMetrics
{
    public function __construct(
        public int $views = 0,
        public int $likes = 0,
        public int $replies = 0,
        public int $reposts = 0,
    ) {}

    /**
     * @return array{views: int, likes: int, replies: int, reposts: int}
     */
    public function toArray(): array
    {
        return [
            'views' => $this->views,
            'likes' => $this->likes,
            'replies' => $this->replies,
            'reposts' => $this->reposts,
        ];
    }
}

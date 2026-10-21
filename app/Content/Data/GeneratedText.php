<?php

namespace App\Content\Data;

/**
 * What the model wrote. The link reply still holds the {link} placeholder.
 */
final readonly class GeneratedText
{
    /**
     * @param  array<string, int>  $usage  Token counts as reported by the provider.
     */
    public function __construct(
        public string $post,
        public string $linkReply,
        public string $model,
        public array $usage = [],
    ) {}

    /**
     * @return array{post: string, link_reply: string}
     */
    public function toArray(): array
    {
        return ['post' => $this->post, 'link_reply' => $this->linkReply];
    }
}

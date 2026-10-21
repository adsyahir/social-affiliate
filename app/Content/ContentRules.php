<?php

namespace App\Content;

use App\Content\Data\GeneratedText;
use App\Models\Product;

/**
 * The posting rules, checked in code because a prompt alone cannot guarantee them.
 * Each problem is phrased as feedback the model can act on.
 */
class ContentRules
{
    /**
     * A URL or something that reads as a domain (shopee.com.my, s.id/x).
     */
    private const LINK_PATTERN = '~https?://|www\.|\b[a-z0-9-]+\.(?:com|net|org|my|co|ee|io|ly|gl|me|id|sg|ph|th|vn|app|link|to)\b~i';

    /**
     * First-person claims of owning or using something, in English and Malay. Deliberately tied to
     * "I/aku/saya" so that "many buyers have used it" is not flagged.
     */
    private const PERSONAL_CLAIM_PATTERN = '~\b(?:i(?:\'ve| have)? (?:been using|bought|used|tried|tested|own(?:ed)?)|my experience with|(?:aku|saya|i) (?:dah |sudah |pernah )?(?:guna|beli|pakai|cuba|try|test))\b~iu';

    public static function containsLink(string $text): bool
    {
        return preg_match(self::LINK_PATTERN, $text) === 1;
    }

    /**
     * @return list<string>
     */
    public function check(GeneratedText $text, Product $product, int $maxLength, bool $includePersonalClaim = true): array
    {
        $problems = [];
        $placeholder = PromptBuilder::LINK_PLACEHOLDER;

        if (trim($text->post) === '') {
            $problems[] = 'The post was empty.';
        }

        if (self::containsLink($text->post)) {
            $problems[] = 'The post contained a link or domain name; remove it, the link belongs only in the reply.';
        }

        if (mb_strlen($text->post) > $maxLength) {
            $problems[] = 'The post was '.mb_strlen($text->post)." characters; keep it well under {$maxLength}.";
        }

        if (substr_count($text->linkReply, $placeholder) !== 1) {
            $problems[] = "The reply must contain the placeholder {$placeholder} exactly once.";
        }

        if (self::containsLink(str_replace($placeholder, '', $text->linkReply))) {
            $problems[] = "The reply contained a URL of its own; use only the {$placeholder} placeholder.";
        }

        if ($includePersonalClaim && ($claim = $this->personalClaim($text, $product)) !== null) {
            $problems[] = $claim;
        }

        return $problems;
    }

    /**
     * A first-person claim of using a product that is not marked as used. For AI drafts this
     * is a problem; for text you wrote yourself it is only a warning, since you know what you used.
     */
    public function personalClaim(GeneratedText $text, Product $product): ?string
    {
        if ($product->used_personally || ! preg_match(self::PERSONAL_CLAIM_PATTERN, $text->post.' '.$text->linkReply, $match)) {
            return null;
        }

        return "It claimed personal use (\"{$match[0]}\") but you have not used this product; write it as a recommendation or find instead.";
    }
}

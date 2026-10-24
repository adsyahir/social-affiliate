<?php

namespace App\Content;

use App\Content\Data\GeneratedText;

/**
 * Reads text you paste: either Claude's whole answer in the POST: / REPLY: format the copy
 * prompt asks for, or just the post itself.
 */
class ManualContentParser
{
    /**
     * A POST: or REPLY: label at the start of a line, allowing markdown such as **POST:** or ## REPLY.
     */
    private const LABEL = '/^[ \t>#*_]*(POST|REPLY)[ \t*_]*:[ \t*_]*/mi';

    /**
     * Whether the text is a whole answer in the POST: / REPLY: format.
     */
    public static function looksLikeAnswer(string $text): bool
    {
        return preg_match('/^[ \t>#*_]*POST[ \t*_]*:/mi', $text) === 1;
    }

    public function parse(string $text, ?string $reply = null): GeneratedText
    {
        // Claude sometimes wraps its answer in a code block.
        $text = trim((string) preg_replace('/^```[a-z]*[ \t]*$/mi', '', $text));

        $sections = $this->sections($text);
        $post = $sections['POST'] ?? ($sections === [] ? $text : '');
        $linkReply = filled($reply) ? trim($reply) : ($sections['REPLY'] ?? PromptBuilder::LINK_PLACEHOLDER);

        return new GeneratedText(post: trim($post), linkReply: trim($linkReply), model: 'manual');
    }

    /**
     * @return array<string, string>
     */
    private function sections(string $text): array
    {
        if (preg_match_all(self::LABEL, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
            return [];
        }

        $sections = [];
        foreach ($matches as $i => $match) {
            $start = $match[0][1] + strlen($match[0][0]);
            $end = $matches[$i + 1][0][1] ?? strlen($text);
            $sections[strtoupper($match[1][0])] = trim(substr($text, $start, $end - $start));
        }

        return $sections;
    }
}

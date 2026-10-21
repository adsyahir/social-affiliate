<?php

namespace App\Content;

use App\Affiliates\AffiliateProviderFactory;
use App\Affiliates\Exceptions\AffiliateApiException;
use App\Affiliates\Exceptions\AffiliateException;
use App\Affiliates\Exceptions\UnsupportedProgramException;
use App\Content\Contracts\ContentGenerator;
use App\Content\Data\ContentRequest;
use App\Content\Data\GeneratedText;
use App\Content\Exceptions\ContentGenerationException;
use App\Content\Exceptions\ContentRejectedException;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Social\SocialPublisherFactory;
use Illuminate\Support\Facades\Log;

/**
 * Writes a draft post for a product and account: the tracked link is resolved first, the model
 * writes in the account's persona, the posting rules are checked (with one corrected retry),
 * and the link and disclosure are filled in by code.
 */
class DraftWriter
{
    private const DEFAULT_MAX_LENGTH = 500;

    public function __construct(
        private readonly ContentGenerator $generator,
        private readonly PromptBuilder $prompts,
        private readonly ContentRules $rules,
        private readonly AffiliateProviderFactory $affiliates,
        private readonly SocialPublisherFactory $publishers,
    ) {}

    /**
     * @throws ContentGenerationException
     */
    public function create(Product $product, SocialAccount $account, ?Campaign $campaign = null): Post
    {
        $campaign ??= $this->campaignFor($product, $account);
        $link = $this->link($product, $account, $campaign);

        [$text, $meta] = $this->write(new ContentRequest($product, $account), $this->maxLength($account));

        return Post::create([
            'campaign_id' => $campaign->id,
            'product_id' => $product->id,
            'social_account_id' => $account->id,
            'status' => PostStatus::Draft,
            ...$this->fill($text, $account, $link),
            'generation' => [...$meta, 'link' => $link],
        ]);
    }

    /**
     * Write a new version of a draft or rejected post, optionally guided by feedback.
     *
     * @throws ContentGenerationException
     */
    public function regenerate(Post $post, ?string $feedback = null): Post
    {
        if (! in_array($post->status, [PostStatus::Draft, PostStatus::Rejected], true) || $post->product === null) {
            throw new ContentGenerationException('Only draft or rejected product posts can be regenerated.');
        }

        $account = $post->socialAccount;
        $link = $post->generation['link'] ?? $this->link($post->product, $account, $post->campaign);

        $previous = [
            'post' => $post->content,
            'link_reply' => str_replace($link, PromptBuilder::LINK_PLACEHOLDER, (string) $post->link_reply_content),
        ];

        [$text, $meta] = $this->write(
            new ContentRequest($post->product, $account, $feedback ?: 'Write a different version.', $previous),
            $this->maxLength($account),
        );

        $post->update([
            'status' => PostStatus::Draft,
            ...$this->fill($text, $account, $link),
            'generation' => [
                ...$meta,
                'link' => $link,
                'regenerations' => ($post->generation['regenerations'] ?? 0) + 1,
            ],
        ]);

        return $post;
    }

    /**
     * @return array{GeneratedText, array<string, mixed>}
     */
    private function write(ContentRequest $request, int $maxLength): array
    {
        $usage = [];
        $text = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $text = $this->generator->generate($this->prompts->build($request, $maxLength));

            foreach ($text->usage as $key => $count) {
                $usage[$key] = ($usage[$key] ?? 0) + $count;
            }

            $problems = $this->rules->check($text, $request->product, $maxLength);

            if ($problems === []) {
                return [$text, ['model' => $text->model, 'attempts' => $attempt, 'usage' => $usage]];
            }

            // Second try: show the model its draft and exactly what was wrong with it.
            $feedback = implode("\n", array_filter([$request->feedback, 'Fix these problems:', ...array_map(fn ($p) => "- {$p}", $problems)]));
            $request = new ContentRequest($request->product, $request->account, $feedback, $text->toArray());
        }

        // Nothing is saved, but the tokens were spent; keep a record of what they bought.
        Log::warning('Draft rejected by the posting rules', [
            'product_id' => $request->product->id,
            'social_account_id' => $request->account->id,
            'model' => $text?->model,
            'usage' => $usage,
            'problems' => $problems,
        ]);

        throw new ContentRejectedException($problems);
    }

    /**
     * @return array{content: string, link_reply_content: string}
     */
    private function fill(GeneratedText $text, SocialAccount $account, string $link): array
    {
        $reply = str_replace(PromptBuilder::LINK_PLACEHOLDER, $link, $text->linkReply);
        $disclosure = PromptBuilder::disclosure($account);

        // The disclosure is required on every link reply, whatever the model wrote.
        if (! str_contains(mb_strtolower($reply), mb_strtolower($disclosure))) {
            $reply .= "\n\n".$disclosure;
        }

        return ['content' => $text->post, 'link_reply_content' => $reply];
    }

    private function link(Product $product, SocialAccount $account, ?Campaign $campaign): string
    {
        $subIds = array_values(array_filter([$account->platform->value.$account->id, $campaign ? 'c'.$campaign->id : null]));

        try {
            return $this->affiliates->make($product->affiliateAccount)->trackingLink($product, $subIds);
        } catch (UnsupportedProgramException) {
            // No driver yet for this program: the pasted link already carries its tracking.
            return $product->affiliate_link;
        } catch (AffiliateException $e) {
            throw new ContentGenerationException($e->getMessage(), retryable: $e instanceof AffiliateApiException && $e->retryable);
        }
    }

    private function campaignFor(Product $product, SocialAccount $account): Campaign
    {
        return Campaign::query()
            ->where('affiliate_account_id', $product->affiliate_account_id)
            ->whereHas('socialAccounts', fn ($query) => $query->whereKey($account->id))
            ->orderByDesc('active')
            ->first()
            ?? throw new ContentGenerationException("\"{$account->name}\" is not in a campaign for this product's affiliate account.");
    }

    private function maxLength(SocialAccount $account): int
    {
        return SocialPublisherFactory::supports($account->platform)
            ? $this->publishers->make($account)->maxLength()
            : self::DEFAULT_MAX_LENGTH;
    }
}

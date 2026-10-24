<?php

namespace App\Telegram;

use App\Affiliates\AffiliateProviderFactory;
use App\Affiliates\Exceptions\AffiliateException;
use App\Affiliates\Exceptions\UnsupportedProgramException;
use App\Enums\AffiliateProgram;
use App\Enums\ContentSource;
use App\Enums\PostStatus;
use App\Jobs\GenerateDraft;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Telegram\Exceptions\TelegramException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Starts a post from a product link sent to the bot: asks which account (when there is more than
 * one) and who writes it, then hands over to the same job a scheduled slot uses.
 *
 * The link waits in the cache until you choose, and the product is only saved then, so a question
 * you ignore never adds a product the scheduler could pick up. Message text is untrusted input;
 * only the approval chat reaches this class.
 */
class LinkIntake
{
    /**
     * How long the account and "who writes" buttons keep working.
     */
    private const PENDING_HOURS = 24;

    /**
     * Blocks a second start for the same link and account while the first draft is being written.
     */
    private const START_LOCK_MINUTES = 10;

    public function __construct(
        private readonly TelegramBot $bot,
        private readonly AffiliateProviderFactory $affiliates,
        private readonly ApprovalChat $chat,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $entities  Telegram's link data for the message
     */
    public static function containsLink(string $text, array $entities = []): bool
    {
        return self::firstUrl($text, $entities) !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     */
    public function handle(string $chatId, int $messageId, string $text, array $entities = []): void
    {
        $sent = (string) self::firstUrl($text, $entities);
        $program = $this->programFor($sent);

        if ($program === null) {
            $this->say($chatId, $messageId, 'I can start posts from Shopee and Amazon links. Send a product link from one of those.');

            return;
        }

        $url = self::normalize($sent);
        $existing = $this->existingProduct($program, $url);
        $affiliate = $existing?->affiliateAccount ?? $this->affiliateFor($program);

        if ($affiliate === null) {
            $this->say($chatId, $messageId, AffiliateAccount::query()->where('program', $program)->exists()
                ? "No social account is in a campaign for your {$program->getLabel()} affiliate account yet. Add one to a campaign in the admin, then send the link again."
                : "Add a {$program->getLabel()} affiliate account in the admin first, then send the link again.");

            return;
        }

        $accounts = $this->accountsFor($affiliate);

        if ($accounts->isEmpty()) {
            $this->say($chatId, $messageId, "No social account is in a campaign for \"{$affiliate->name}\" yet. Add one to a campaign in the admin, then send the link again.");

            return;
        }

        $pending = $existing !== null
            ? ['product_id' => $existing->id, 'name' => $existing->name]
            : $this->newProduct($affiliate, $sent, $url, $text, $entities, $chatId, $messageId);

        if ($pending === null) {
            return;
        }

        $token = Str::random(10);
        Cache::put(self::pendingKey($token), $pending, now()->addHours(self::PENDING_HOURS));

        if ($accounts->count() === 1) {
            $account = $accounts->first();
            $this->safely(fn () => $this->bot->sendMessage($chatId, self::whoWritesText($pending['name'], $account), self::whoWritesButtons($token, $account), $messageId));

            return;
        }

        $buttons = $accounts->map(fn (SocialAccount $account): array => [
            'text' => $account->name,
            'callback_data' => "start:{$token}:{$account->id}",
        ])->values()->all();

        $this->safely(fn () => $this->bot->sendMessage(
            $chatId,
            '🛍 <b>'.e($pending['name']).'</b>'."\n\nWhich account should this go to?",
            array_chunk($buttons, 2),
            $messageId,
        ));
    }

    /**
     * A tap on an account button, with data "start:<token>:<account>". The same message then
     * asks who writes the post.
     *
     * @return string the short answer shown on the tap
     */
    public function choose(string $chatId, int $questionMessageId, string $data): string
    {
        [$token, $accountId] = array_pad(explode(':', $data, 2), 2, '');
        [$pending, $account] = $this->resolve($token, (int) $accountId);

        if ($pending === null) {
            return 'This link has expired or the account is no longer in its campaign. Send the link again.';
        }

        $this->safely(fn () => $this->bot->editMessageText($chatId, $questionMessageId, self::whoWritesText($pending['name'], $account), self::whoWritesButtons($token, $account)));

        return 'Who writes it?';
    }

    /**
     * A tap on "I write" or "AI writes", with data "write:<token>:<account>:<manual|ai>".
     *
     * @return string the short answer shown on the tap
     */
    public function write(string $chatId, int $questionMessageId, string $data): string
    {
        [$token, $accountId, $choice] = array_pad(explode(':', $data, 3), 3, '');
        [$pending, $account] = $this->resolve($token, (int) $accountId);
        $source = ContentSource::tryFrom($choice);

        if ($pending === null || $source === null) {
            return 'This link has expired or the account is no longer in its campaign. Send the link again.';
        }

        // Without a key the draft would only fail later; say so now and keep the buttons.
        if ($source === ContentSource::Ai && blank(config('services.anthropic.api_key'))) {
            return 'AI needs a Claude API key on the server (ANTHROPIC_API_KEY). Tap "I write" for now.';
        }

        $product = $this->saveProduct($token, $pending);

        if (! $this->start($product, $account, $source, $chatId, $questionMessageId)) {
            return 'Already waiting.';
        }

        // The question loses its buttons, so a second tap cannot start a second post.
        $this->safely(fn () => $this->bot->editMessageText(
            $chatId,
            $questionMessageId,
            self::heading($product->name, $account)."\n\n".($source === ContentSource::Manual
                ? '✍️ You write it. The "Your turn" message with the prompt follows.'
                : '🤖 AI writes it. The draft follows for approval.'),
        ));

        return 'Starting...';
    }

    /**
     * @return bool whether a post was started
     */
    private function start(Product $product, SocialAccount $account, ContentSource $source, string $chatId, int $replyTo): bool
    {
        $open = Post::query()
            ->whereBelongsTo($product)
            ->whereBelongsTo($account)
            ->whereIn('status', [PostStatus::Pending, PostStatus::Draft])
            ->first();

        // Already waiting: bring it down here, so you can reply to it straight away.
        if ($open !== null) {
            $this->chat->continueBelow($open, $replyTo, '⬆️ This post was already waiting. Reply to this message with your text.');

            return false;
        }

        // Covers the time before the job has created the post, e.g. a double tap.
        if (! Cache::add("telegram-start:{$product->id}:{$account->id}", true, now()->addMinutes(self::START_LOCK_MINUTES))) {
            $this->say($chatId, $replyTo, "A post for this product on \"{$account->name}\" is already being started.");

            return false;
        }

        $campaign = Campaign::query()
            ->where('affiliate_account_id', $product->affiliate_account_id)
            ->whereHas('socialAccounts', fn ($query) => $query->whereKey($account->id))
            ->orderByDesc('active')
            ->firstOrFail();

        GenerateDraft::dispatch($account, $product, $campaign, source: $source);

        return true;
    }

    /**
     * Details for a product that does not exist yet, once the link is known to be trackable.
     *
     * @param  list<array<string, mixed>>  $entities
     * @return array{affiliate_account_id: int, name: string, affiliate_link: string, product_url: ?string}|null
     */
    private function newProduct(AffiliateAccount $affiliate, string $sent, string $url, string $text, array $entities, string $chatId, int $messageId): ?array
    {
        $name = $this->nameFrom($text, $entities, $sent);

        if ($name === null) {
            $this->say($chatId, $messageId, 'What is it called? Send the link again with the product name on the next line.');

            return null;
        }

        $product = new Product(['name' => $name, 'affiliate_link' => $url]);
        $product->affiliateAccount()->associate($affiliate);
        $problem = $this->trackingProblem($affiliate, $product);

        if ($problem !== null) {
            // A full product page may still work when the program can turn it into a tracked link.
            $product->product_url = $url;
            $problem = $this->trackingProblem($affiliate, $product);
        }

        if ($problem !== null) {
            $this->say($chatId, $messageId, $problem);

            return null;
        }

        return [
            'affiliate_account_id' => $affiliate->id,
            'name' => $name,
            'affiliate_link' => $url,
            'product_url' => $product->product_url,
        ];
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function saveProduct(string $token, array $pending): Product
    {
        if (isset($pending['product_id'])) {
            return Product::findOrFail($pending['product_id']);
        }

        // Another tap may have saved it meanwhile (two accounts chosen for the same link).
        $product = Product::query()
            ->where('affiliate_account_id', $pending['affiliate_account_id'])
            ->where('affiliate_link', $pending['affiliate_link'])
            ->first()
            ?? Product::create($pending);

        Cache::put(self::pendingKey($token), ['product_id' => $product->id, 'name' => $product->name], now()->addHours(self::PENDING_HOURS));

        return $product;
    }

    /**
     * The waiting link and the account from button data, only when that account may post it.
     *
     * @return array{array<string, mixed>, SocialAccount}|array{null, null}
     */
    private function resolve(string $token, int $accountId): array
    {
        $pending = Cache::get(self::pendingKey($token));
        $account = SocialAccount::find($accountId);

        if (! is_array($pending) || $account === null) {
            return [null, null];
        }

        $affiliate = isset($pending['product_id'])
            ? Product::find($pending['product_id'])?->affiliateAccount
            : AffiliateAccount::find($pending['affiliate_account_id'] ?? null);

        if ($affiliate === null || ! $this->accountsFor($affiliate)->contains($account)) {
            return [null, null];
        }

        return [$pending, $account];
    }

    /**
     * Same check the drafts make, so a link that could never be posted is refused now.
     */
    private function trackingProblem(AffiliateAccount $affiliate, Product $product): ?string
    {
        try {
            $this->affiliates->make($affiliate)->trackingLink($product);

            return null;
        } catch (UnsupportedProgramException) {
            return null;
        } catch (AffiliateException $e) {
            return $e->getMessage();
        }
    }

    private function existingProduct(AffiliateProgram $program, string $url): ?Product
    {
        return Product::query()
            ->whereHas('affiliateAccount', fn ($query) => $query->where('program', $program))
            ->where(fn ($query) => $query->where('affiliate_link', $url)->orWhere('product_url', $url))
            ->orderBy('id')
            ->first();
    }

    /**
     * The first account of the program that has somewhere to post.
     */
    private function affiliateFor(AffiliateProgram $program): ?AffiliateAccount
    {
        return AffiliateAccount::query()
            ->where('program', $program)
            ->whereHas('campaigns.socialAccounts')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     */
    private function nameFrom(string $text, array $entities, string $url): ?string
    {
        $typed = $text;

        foreach (self::urls($text, $entities) as $link) {
            $typed = str_replace($link, ' ', $typed);
        }

        // Text added by the Shopee and Amazon share buttons is not part of the name.
        $typed = preg_replace([
            '/^\s*check out\s+/i',
            '/\s*(get it|buy it|shop now|grab it)\b.*\bshopee\b.*$/i',
            '/\s+for RM\s?[\d.,]+[.!]?\s*$/i',
            '/^\s*amazon\.[a-z.]+\s*:\s*/i',
        ], '', trim((string) preg_replace(['/[\x{FFFC}\x{FFFD}\p{Cf}]/u', '/\s+/'], ['', ' '], $typed)));

        $typed = trim((string) $typed, " \t:-");

        if ($typed !== '') {
            return Str::limit($typed, 250, '');
        }

        // Shopee and Amazon product pages carry the title in the path; short links do not.
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        if (! mb_check_encoding($path, 'UTF-8')) {
            return null;
        }

        $slug = preg_match('~^/([^/]+)/(?:dp|gp/product)/~i', $path, $amazon) === 1
            ? $amazon[1]
            : preg_replace('/-i\.\d+\.\d+$/', '', trim($path, '/'));

        if ($slug === '' || ! str_contains($slug, '-') || str_contains($slug, '/')) {
            return null;
        }

        return Str::limit(trim(str_replace('-', ' ', $slug)), 250, '');
    }

    /**
     * One form per product page, so the same product sent twice is recognised: tracking parameters
     * are dropped and Amazon pages become /dp/<ASIN>. Short links are kept as they are.
     */
    private static function normalize(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        if (preg_match('/(^|\.)amazon\./', $host) === 1
            && preg_match('~/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})(?:[/?]|$)~i', $parts['path'] ?? '', $asin) === 1) {
            return "https://{$host}/dp/".strtoupper($asin[1]);
        }

        if (preg_match('/^(www\.)?shopee\.|(^|\.)amazon\./', $host) === 1) {
            return "https://{$host}".($parts['path'] ?? '/');
        }

        return $url;
    }

    private function programFor(string $url): ?AffiliateProgram
    {
        foreach (AffiliateProgram::cases() as $program) {
            if (! AffiliateProviderFactory::supports($program)) {
                continue;
            }

            if ($this->affiliates->make(new AffiliateAccount(['program' => $program]))->supportsUrl($url)) {
                return $program;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, SocialAccount>
     */
    private function accountsFor(AffiliateAccount $affiliate): Collection
    {
        return SocialAccount::query()
            ->whereHas('campaigns', fn ($query) => $query->where('affiliate_account_id', $affiliate->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     */
    private static function firstUrl(string $text, array $entities): ?string
    {
        return self::urls($text, $entities)[0] ?? null;
    }

    /**
     * Links in the message: Telegram's own link data first (it also covers linked words), then
     * anything that looks like a link.
     *
     * @param  list<array<string, mixed>>  $entities
     * @return list<string>
     */
    private static function urls(string $text, array $entities): array
    {
        $urls = [];
        // Telegram gives offsets and lengths in UTF-16 units (an emoji counts as 2).
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        foreach ($entities as $entity) {
            if (($entity['type'] ?? null) === 'text_link' && isset($entity['url'])) {
                $urls[] = (string) $entity['url'];
            } elseif (($entity['type'] ?? null) === 'url') {
                $urls[] = mb_convert_encoding(substr($utf16, 2 * (int) $entity['offset'], 2 * (int) $entity['length']), 'UTF-8', 'UTF-16LE');
            }
        }

        preg_match_all('~https?://[^\s<>"]+~i', $text, $matches);

        foreach ($matches[0] as $match) {
            $urls[] = rtrim($match, '.,);:!?\'');
        }

        return array_values(array_unique(array_filter($urls, fn (string $url): bool => preg_match('~^https?://~i', $url) === 1)));
    }

    private static function pendingKey(string $token): string
    {
        return "telegram-link:{$token}";
    }

    private static function heading(string $productName, SocialAccount $account): string
    {
        return '🛍 <b>'.e($productName).'</b> → <b>'.e($account->name).'</b>';
    }

    private static function whoWritesText(string $productName, SocialAccount $account): string
    {
        return self::heading($productName, $account)."\n\nWho writes this one?";
    }

    /**
     * @return list<list<array{text: string, callback_data: string}>>
     */
    private static function whoWritesButtons(string $token, SocialAccount $account): array
    {
        $data = "write:{$token}:{$account->id}";

        return [[
            ['text' => '✍️ I write', 'callback_data' => "{$data}:".ContentSource::Manual->value],
            ['text' => '🤖 AI writes', 'callback_data' => "{$data}:".ContentSource::Ai->value],
        ]];
    }

    private function say(string $chatId, int $replyTo, string $text): void
    {
        $this->safely(fn () => $this->bot->sendMessage($chatId, e($text), replyToMessageId: $replyTo));
    }

    private function safely(callable $call): void
    {
        try {
            $call();
        } catch (TelegramException $e) {
            Log::warning('Telegram update failed', ['error' => $e->getMessage()]);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Social\Contracts\RefreshesAccessToken;
use App\Social\Exceptions\SocialException;
use App\Social\SocialPublisherFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshSocialTokens extends Command
{
    protected $signature = 'social:refresh-tokens
        {--days=7 : Refresh tokens that expire within this many days}';

    protected $description = 'Renew social account tokens before they expire (Threads tokens last 60 days)';

    public function handle(SocialPublisherFactory $publishers): int
    {
        $accounts = SocialAccount::query()
            ->whereNotNull('access_token')
            // An unknown expiry is refreshed too, which also records when the new token expires.
            ->where(fn ($query) => $query
                ->whereNull('token_expires_at')
                ->orWhere('token_expires_at', '<=', now()->addDays((int) $this->option('days'))))
            ->get()
            ->filter(fn (SocialAccount $account): bool => SocialPublisherFactory::supports($account->platform));

        $failed = 0;

        foreach ($accounts as $account) {
            $publisher = $publishers->make($account);

            if (! $publisher instanceof RefreshesAccessToken) {
                continue;
            }

            if ($account->token_expires_at?->isPast()) {
                $failed++;
                $this->error("{$account->name}: the token expired {$account->token_expires_at->diffForHumans()} and cannot be refreshed. Paste a new one.");
                Log::error('Social token expired', ['social_account_id' => $account->id]);

                continue;
            }

            try {
                $token = $publisher->refreshAccessToken();
            } catch (SocialException $e) {
                $failed++;
                $this->error("{$account->name}: {$e->getMessage()}");
                Log::error('Social token refresh failed', ['social_account_id' => $account->id, 'error' => $e->getMessage()]);

                continue;
            }

            $account->update(['access_token' => $token->token, 'token_expires_at' => $token->expiresAt]);
            $this->info("{$account->name}: refreshed, valid until {$token->expiresAt?->toDateString()}.");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

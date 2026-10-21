<?php

namespace App\Filament\Resources\SocialAccounts\Actions;

use App\Models\SocialAccount;
use App\Social\Contracts\RefreshesAccessToken;
use App\Social\Contracts\SocialPublisher;
use App\Social\Data\PostContent;
use App\Social\Exceptions\SocialException;
use App\Social\SocialPublisherFactory;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Buttons on the social account page for checking that its token actually works.
 */
class ConnectionActions
{
    public static function make(): ActionGroup
    {
        return ActionGroup::make([
            self::checkConnection(),
            self::refreshToken(),
            self::sendTestPost(),
        ])
            ->label('Connection')
            ->icon(Heroicon::OutlinedSignal)
            ->button()
            ->color('gray');
    }

    private static function checkConnection(): Action
    {
        return Action::make('checkConnection')
            ->label('Check connection')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->visible(fn (SocialAccount $record): bool => self::canConnect($record))
            ->action(function (SocialAccount $record, Action $action, mixed $livewire): void {
                $profile = self::attempt($action, 'Connection failed', fn () => self::publisher($record)->verify());

                // The platform's own ID is needed to publish; fill it in rather than asking for it.
                if (blank($record->external_id)) {
                    $record->update(['external_id' => $profile->id]);
                    self::refreshForm($livewire, ['external_id']);
                }

                $notification = Notification::make()->title("Connected as {$profile->displayName()}");

                if (! $profile->identifies((string) $record->external_id)) {
                    $notification->warning()->body("This token belongs to {$profile->displayName()} ({$profile->id}), not {$record->external_id}.");
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }

    private static function refreshToken(): Action
    {
        return Action::make('refreshToken')
            ->label('Refresh token')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (SocialAccount $record): bool => self::canConnect($record)
                && self::publisher($record) instanceof RefreshesAccessToken)
            ->requiresConfirmation()
            ->modalDescription('Swaps the stored token for a new one valid for another 60 days. Tokens must be at least a day old to refresh.')
            ->action(function (SocialAccount $record, Action $action, mixed $livewire): void {
                /** @var RefreshesAccessToken $publisher */
                $publisher = self::publisher($record);
                $token = self::attempt($action, 'Refresh failed', fn () => $publisher->refreshAccessToken());

                $record->update(['access_token' => $token->token, 'token_expires_at' => $token->expiresAt]);
                self::refreshForm($livewire, ['token_expires_at']);

                Notification::make()
                    ->title('Token refreshed')
                    ->body($token->expiresAt ? "Valid until {$token->expiresAt->toFormattedDateString()}." : null)
                    ->success()
                    ->send();
            });
    }

    private static function sendTestPost(): Action
    {
        return Action::make('sendTestPost')
            ->label('Send test post')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (SocialAccount $record): bool => self::canConnect($record))
            ->modalHeading('Send a test post')
            ->modalDescription('This publishes a real, public post on this account. Delete it on the platform afterwards if you like.')
            ->modalSubmitActionLabel('Publish')
            ->schema([
                Textarea::make('text')
                    ->label('Post')
                    ->default('Testing my posting setup. Please ignore.')
                    ->required()
                    ->rows(3),
                Textarea::make('reply_text')
                    ->label('Reply (optional)')
                    ->helperText('Posted under the test post, the way affiliate links will be.')
                    ->rows(2),
            ])
            ->action(function (SocialAccount $record, Action $action, array $data): void {
                $publisher = self::publisher($record);
                $post = self::attempt($action, 'Test post failed', fn () => $publisher->publish(new PostContent($data['text'])));

                $replyNote = null;
                if (filled($data['reply_text'] ?? null)) {
                    try {
                        $publisher->reply($post->id, new PostContent($data['reply_text']));
                        $replyNote = 'The reply was posted too.';
                    } catch (SocialException $e) {
                        $replyNote = "The post is live but the reply failed: {$e->getMessage()}";
                    }
                }

                Notification::make()
                    ->title('Test post published')
                    ->body(implode(' ', array_filter([$post->permalink, $replyNote])))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Run an API call, turning a failure into a notification and keeping the modal open.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private static function attempt(Action $action, string $failureTitle, callable $call): mixed
    {
        try {
            return $call();
        } catch (SocialException $e) {
            Notification::make()->title($failureTitle)->body($e->getMessage())->danger()->send();
            $action->halt();
        }
    }

    /**
     * Copy values an action just saved into the open form, so a later Save does not write the
     * stale form values back over them.
     *
     * @param  list<string>  $attributes
     */
    private static function refreshForm(mixed $livewire, array $attributes): void
    {
        if ($livewire instanceof EditRecord) {
            $livewire->refreshFormData($attributes);
        }
    }

    private static function canConnect(SocialAccount $account): bool
    {
        return SocialPublisherFactory::supports($account->platform) && filled($account->access_token);
    }

    private static function publisher(SocialAccount $account): SocialPublisher
    {
        return app(SocialPublisherFactory::class)->make($account);
    }
}

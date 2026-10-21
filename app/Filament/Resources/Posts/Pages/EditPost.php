<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Content\DraftWriter;
use App\Content\Exceptions\ContentGenerationException;
use App\Enums\PostStatus;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Posts\Schemas\PostForm;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Jobs\FetchPostInsights;
use App\Jobs\SyncApprovalMessage;
use App\Models\Post;
use App\Social\SocialPublisherFactory;
use App\Telegram\TelegramBot;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshInsights')
                ->label('Refresh insights')
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->visible(fn (Post $record): bool => $record->status === PostStatus::Published && $record->external_post_id !== null
                    && SocialPublisherFactory::supportsInsights($record->socialAccount->platform))
                ->action(function (Post $record): void {
                    // Run in this request so the new numbers can be shown straight away.
                    $snapshot = app()->call([new FetchPostInsights($record), 'handle']);
                    $record->unsetRelation('latestInsight');

                    $snapshot === null
                        ? Notification::make()->title('Could not fetch insights')->body('See the log for the reason, often an expired token.')->danger()->send()
                        : Notification::make()->title('Insights updated')->body(number_format($snapshot->views).' views, '.number_format($snapshot->likes).' likes')->success()->send();
                }),
            Action::make('sendToTelegram')
                ->label('Send to Telegram')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->action(function (Post $record, TelegramBot $bot): void {
                    if (! $bot->configured()) {
                        Notification::make()->title('Telegram is not set up')->body('Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID in .env.')->danger()->send();

                        return;
                    }

                    SyncApprovalMessage::dispatch($record);
                    Notification::make()->title('Queued for Telegram')->body('It arrives once the queue worker picks it up.')->success()->send();
                }),
            Action::make('writePost')
                ->label('Write post')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn (Post $record): bool => $record->status === PostStatus::Pending || ($record->writtenByYou() && PostForm::editable($record)))
                ->modalDescription('Paste your post, or Claude\'s whole answer. The tracked link and disclosure are added for you.')
                ->modalSubmitActionLabel('Save draft')
                ->schema([
                    TextEntry::make('prompt')
                        ->label('Prompt to paste into Claude.ai (click to copy)')
                        ->state(fn (): string => app(DraftWriter::class)->copyPrompt($this->getRecord()->product, $this->getRecord()->socialAccount))
                        // Shown with its line breaks; the copy button copies the plain text.
                        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(nl2br(e($state))))
                        ->copyable()
                        ->copyableState(fn (string $state): string => $state)
                        ->copyMessage('Prompt copied')
                        ->extraAttributes(['style' => 'max-height: 16rem; overflow-y: auto;']),
                    Textarea::make('text')
                        ->label('Post, or Claude\'s whole answer')
                        ->rows(8)
                        ->required(),
                    Textarea::make('reply')
                        ->label('Link reply (optional)')
                        ->helperText('Leave empty to use the REPLY part of Claude\'s answer, or just the link.')
                        ->rows(3),
                ])
                ->action(function (Post $record, Action $action, array $data, DraftWriter $writer): void {
                    try {
                        $writer->fillFromText($record, $data['text'], $data['reply'] ?? null);
                    } catch (ContentGenerationException $e) {
                        Notification::make()->title('Please fix the text')->body($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }

                    $this->refreshFormData(['content', 'link_reply_content', 'status']);
                    SyncApprovalMessage::dispatch($record->fresh(), '✏️ Written in the admin.');
                    EditProduct::notifySaved($record->fresh());
                }),
            Action::make('regenerate')
                ->label('Regenerate')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn (Post $record): bool => PostForm::editable($record) && $record->product_id !== null && ! $record->writtenByYou())
                ->modalDescription('Writes a new version in the account\'s persona. Unsaved edits to this draft are replaced.')
                ->modalSubmitActionLabel('Regenerate')
                ->schema([
                    Textarea::make('feedback')
                        ->label('What should change? (optional)')
                        ->placeholder('Shorter, more casual, mention the price drop...')
                        ->rows(3),
                ])
                ->action(function (Post $record, Action $action, array $data, DraftWriter $writer): void {
                    // Writing can take tens of seconds, past PHP's default 30-second limit.
                    set_time_limit(180);

                    try {
                        $writer->regenerate($record, $data['feedback'] ?? null);
                    } catch (ContentGenerationException $e) {
                        Notification::make()->title('Could not regenerate')->body($e->getMessage())->danger()->send();
                        $action->halt();
                    }

                    $this->refreshFormData(['content', 'link_reply_content', 'status']);
                    SyncApprovalMessage::dispatch($record->fresh(), '🔄 New version from the admin.');
                    Notification::make()->title('New version written')->success()->send();
                }),
            DeleteAction::make()
                ->visible(fn (Post $record): bool => PostForm::editable($record) || $record->status === PostStatus::Pending),
        ];
    }

    /**
     * Keep the Telegram approval message in step with edits made here.
     */
    protected function afterSave(): void
    {
        if ($this->record->telegram_message_id !== null) {
            SyncApprovalMessage::dispatch($this->record, '✏️ Edited in the admin.');
        }
    }
}

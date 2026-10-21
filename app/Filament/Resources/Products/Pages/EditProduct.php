<?php

namespace App\Filament\Resources\Products\Pages;

use App\Affiliates\AffiliateProviderFactory;
use App\Affiliates\Exceptions\AffiliateException;
use App\Content\DraftWriter;
use App\Content\Exceptions\ContentGenerationException;
use App\Content\Exceptions\ContentInvalidException;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Products\ProductResource;
use App\Jobs\SyncApprovalMessage;
use App\Models\Post;
use App\Models\Product;
use App\Models\SocialAccount;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateDraft')
                ->label('Generate draft')
                ->icon(Heroicon::OutlinedSparkles)
                ->modalDescription('Writes a post and link reply in the account\'s persona. Nothing is published; the draft waits for approval.')
                ->modalSubmitActionLabel('Generate')
                ->schema([
                    Select::make('social_account_id')
                        ->label('For account')
                        ->options(fn (Product $record): array => self::campaignAccounts($record))
                        ->helperText('Only accounts in a campaign for this product\'s affiliate account.')
                        ->required(),
                ])
                ->action(function (Product $record, Action $action, array $data, DraftWriter $writer): void {
                    // Writing can take tens of seconds, past PHP's default 30-second limit.
                    set_time_limit(180);

                    try {
                        $post = $writer->create($record, SocialAccount::findOrFail($data['social_account_id']));
                    } catch (ContentGenerationException $e) {
                        Notification::make()->title('Could not write a draft')->body($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }

                    SyncApprovalMessage::dispatch($post);
                    Notification::make()->title('Draft written')->body('It is also on its way to the Telegram approval chat, if Telegram is set up.')->success()->send();
                    $this->redirect(PostResource::getUrl('edit', ['record' => $post]));
                }),
            Action::make('writePost')
                ->label('Write post')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->modalDescription('Use your own text, for example Claude\'s answer from Claude.ai. The tracked link and disclosure are added for you, and the draft goes for approval as usual.')
                ->modalSubmitActionLabel('Save draft')
                ->schema([
                    Select::make('social_account_id')
                        ->label('For account')
                        ->options(fn (Product $record): array => self::campaignAccounts($record))
                        ->helperText('Only accounts in a campaign for this product\'s affiliate account.')
                        ->live()
                        ->required(),
                    TextEntry::make('prompt')
                        ->label('Prompt to paste into Claude.ai (click to copy)')
                        ->state(fn (Get $get): ?string => filled($get('social_account_id'))
                            ? app(DraftWriter::class)->copyPrompt($this->getRecord(), SocialAccount::findOrFail($get('social_account_id')))
                            : null)
                        // Shown with its line breaks; the copy button copies the plain text.
                        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(nl2br(e($state))))
                        ->copyable()
                        ->copyableState(fn (string $state): string => $state)
                        ->copyMessage('Prompt copied')
                        ->extraAttributes(['style' => 'max-height: 16rem; overflow-y: auto;'])
                        ->visible(fn (Get $get): bool => filled($get('social_account_id'))),
                    Textarea::make('text')
                        ->label('Post, or Claude\'s whole answer')
                        ->placeholder("POST:\n...\nREPLY:\n... {link}")
                        ->rows(8)
                        ->required(),
                    Textarea::make('reply')
                        ->label('Link reply (optional)')
                        ->helperText('Leave empty to use the REPLY part of Claude\'s answer, or just the link. Write {link} where the link goes.')
                        ->rows(3),
                ])
                ->action(function (Product $record, Action $action, array $data, DraftWriter $writer): void {
                    try {
                        $post = $writer->createFromText($record, SocialAccount::findOrFail($data['social_account_id']), $data['text'], $data['reply'] ?? null);
                    } catch (ContentInvalidException $e) {
                        Notification::make()->title('Please fix the text')->body(implode(' ', $e->problems))->danger()->send();
                        $action->halt();

                        return;
                    } catch (ContentGenerationException $e) {
                        Notification::make()->title('Could not save the draft')->body($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }

                    SyncApprovalMessage::dispatch($post);
                    self::notifySaved($post);
                    $this->redirect(PostResource::getUrl('edit', ['record' => $post]));
                }),
            // Shows the link a reply would carry, so you can check credentials and links before posting.
            Action::make('previewTrackingLink')
                ->label('Preview tracking link')
                ->icon(Heroicon::OutlinedLink)
                ->color('gray')
                ->action(function (Product $record, AffiliateProviderFactory $providers): void {
                    try {
                        $link = $providers->make($record->affiliateAccount)->trackingLink($record, ['preview']);
                    } catch (AffiliateException $e) {
                        Notification::make()->title('No tracking link')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Tracking link')->body($link)->success()->persistent()->send();
                }),
            // A product that has been posted keeps its history; set it to Archived instead.
            DeleteAction::make()
                ->hidden(fn (Product $record): bool => $record->posts()->exists()),
        ];
    }

    /**
     * Accounts that share a campaign with the product's affiliate account.
     *
     * @return array<int, string>
     */
    private static function campaignAccounts(Product $product): array
    {
        return SocialAccount::query()
            ->whereHas('campaigns', fn ($query) => $query->where('affiliate_account_id', $product->affiliate_account_id))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function notifySaved(Post $post): void
    {
        $warnings = $post->generation['warnings'] ?? [];

        Notification::make()
            ->title('Draft saved')
            ->body($warnings === [] ? 'It is also on its way to the Telegram approval chat, if Telegram is set up.' : 'Check before approving: '.implode(' ', $warnings))
            ->{$warnings === [] ? 'success' : 'warning'}()
            ->send();
    }
}

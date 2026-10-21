<?php

namespace App\Filament\Resources\Products\Pages;

use App\Affiliates\AffiliateProviderFactory;
use App\Affiliates\Exceptions\AffiliateException;
use App\Content\DraftWriter;
use App\Content\Exceptions\ContentGenerationException;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Products\ProductResource;
use App\Jobs\SyncApprovalMessage;
use App\Models\Product;
use App\Models\SocialAccount;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

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
                        ->options(fn (Product $record): array => SocialAccount::query()
                            ->whereHas('campaigns', fn ($query) => $query->where('affiliate_account_id', $record->affiliate_account_id))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
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
}

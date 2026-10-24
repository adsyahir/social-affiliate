<?php

namespace App\Filament\Resources\Posts\Schemas;

use App\Content\ContentRules;
use App\Content\PromptBuilder;
use App\Enums\PostStatus;
use App\Models\Post;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Draft')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Select::make('social_account_id')
                            ->label('Account')
                            ->relationship('socialAccount', 'name')
                            ->disabled(),
                        Select::make('product_id')
                            ->label('Product')
                            ->relationship('product', 'name')
                            ->disabled(),
                        Select::make('status')
                            ->options(PostStatus::class)
                            ->disabled(),
                        Textarea::make('content')
                            ->label('Post')
                            ->helperText('Never put the link here; it goes in the reply.')
                            ->rows(6)
                            ->required()
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (ContentRules::containsLink((string) $value)) {
                                    $fail('Remove the link or domain name; links only go in the reply.');
                                }
                            })
                            ->live(debounce: 500)
                            ->hint(fn (?string $state): string => mb_strlen((string) $state).' characters')
                            ->disabled(fn (?Post $record): bool => ! self::editable($record))
                            ->columnSpanFull(),
                        Textarea::make('link_reply_content')
                            ->label('Link reply')
                            ->helperText('Posted under the post. Keep the link and the disclosure.')
                            ->rows(4)
                            ->required()
                            ->rule(fn (?Post $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                $reply = (string) $value;
                                $link = $record?->generation['link'] ?? null;

                                if ($link !== null && ! str_contains($reply, $link)) {
                                    $fail("Keep the tracked link in the reply: {$link}");
                                }

                                $disclosure = $record?->socialAccount !== null ? PromptBuilder::disclosure($record->socialAccount) : null;

                                if ($disclosure !== null && ! str_contains(mb_strtolower($reply), mb_strtolower($disclosure))) {
                                    $fail('Keep the affiliate disclosure in the reply.');
                                }
                            })
                            ->live(debounce: 500)
                            ->hint(fn (?string $state): string => mb_strlen((string) $state).' characters')
                            ->disabled(fn (?Post $record): bool => ! self::editable($record))
                            ->columnSpanFull(),
                    ]),
                Section::make('Performance')
                    ->columnSpanFull()
                    ->columns(5)
                    ->visible(fn (?Post $record): bool => $record?->status === PostStatus::Published)
                    ->schema([
                        TextEntry::make('latestInsight.views')->label('Views')->numeric()->placeholder('-'),
                        TextEntry::make('latestInsight.likes')->label('Likes')->numeric()->placeholder('-'),
                        TextEntry::make('latestInsight.replies')->label('Replies')->numeric()->placeholder('-'),
                        TextEntry::make('latestInsight.reposts')->label('Reposts')->numeric()->placeholder('-'),
                        TextEntry::make('latestInsight.fetched_at')->label('Updated')->since()->placeholder('Not fetched yet'),
                        TextEntry::make('permalink')->label('Link')->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)->placeholder('-')->columnSpanFull(),
                    ]),
            ]);
    }

    public static function editable(?Post $post): bool
    {
        return $post !== null && in_array($post->status, [PostStatus::Draft, PostStatus::Rejected], true);
    }
}

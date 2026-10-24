<?php

namespace App\Filament\Resources\SocialAccounts\Schemas;

use App\Enums\ContentSource;
use App\Enums\SocialPlatform;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SocialAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('platform')
                            ->options(SocialPlatform::class)
                            ->live()
                            ->required(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('external_id')
                            ->label('Platform account ID')
                            ->helperText(fn (Get $get): string => self::hint($get('platform'))['id'])
                            ->maxLength(255),
                        DateTimePicker::make('token_expires_at')
                            ->label('Token expires at'),
                        // The stored token is never sent back to the browser.
                        TextInput::make('access_token')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation, Get $get): string => self::hint($get('platform'))['token']
                                .($operation === 'edit' ? ' Leave blank to keep the current one.' : ''))
                            ->columnSpanFull(),
                    ]),
                Section::make('Persona')
                    ->columnSpanFull()
                    ->description('Every post and reply for this account is written in this voice.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('persona.language')
                            ->label('Language')
                            ->placeholder('Malay (rojak, mixed with English)')
                            ->required(),
                        TextInput::make('persona.audience')
                            ->label('Target audience')
                            ->placeholder('Malaysian shoppers on Shopee')
                            ->required(),
                        TextInput::make('persona.disclosure')
                            ->label('Affiliate disclosure')
                            ->placeholder('Link affiliate ya, aku dapat komisen sikit kalau korang beli.')
                            ->helperText('Added to every link reply, in the persona\'s language.')
                            ->required()
                            ->maxLength(150)
                            ->columnSpanFull(),
                        ToggleButtons::make('content_source')
                            ->label('Who writes the scheduled posts')
                            ->options(ContentSource::class)
                            ->default(ContentSource::Ai)
                            ->helperText('Either way, you can also use Generate draft (AI) or Write post (your text) on any product.')
                            ->inline()
                            ->live()
                            ->required()
                            ->columnSpanFull(),
                        Select::make('ai_model')
                            ->label('AI model')
                            ->visible(fn (Get $get): bool => self::source($get('content_source')) === ContentSource::Ai)
                            ->options(fn (): array => config('services.anthropic.models'))
                            ->placeholder(fn (): string => 'Default ('.config('services.anthropic.model').')')
                            ->helperText('Which model writes this account\'s posts. Cheaper models cost less per post; compare drafts before switching.')
                            ->columnSpanFull(),
                        Textarea::make('persona.tone')
                            ->label('Tone')
                            ->placeholder('Santai, macam kawan share barang best. Tak hard sell.')
                            ->rows(2)
                            ->required()
                            ->columnSpanFull(),
                        Repeater::make('persona.sample_posts')
                            ->label('Sample posts')
                            ->simple(
                                Textarea::make('text')
                                    ->rows(2)
                                    ->required(),
                            )
                            ->defaultItems(0)
                            ->addActionLabel('Add sample post')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function source(ContentSource|string|null $source): ContentSource
    {
        return $source instanceof ContentSource ? $source : (ContentSource::tryFrom((string) $source) ?? ContentSource::Ai);
    }

    /**
     * What the ID and token fields mean on each platform.
     *
     * @return array{id: string, token: string}
     */
    private static function hint(SocialPlatform|string|null $platform): array
    {
        $platform = is_string($platform) ? SocialPlatform::tryFrom($platform) : $platform;

        return match ($platform) {
            SocialPlatform::Threads => [
                'id' => 'Leave empty: Connection > Check connection fills in the Threads user ID (not the Meta App ID).',
                'token' => 'Long-lived Threads access token (threads_basic, threads_content_publish, threads_manage_replies, threads_manage_insights). It is refreshed automatically.',
            ],
            SocialPlatform::FacebookPage => [
                'id' => 'The Facebook Page ID. Leave empty and use Check connection to fill it in.',
                'token' => 'Page access token made from a long-lived user token, so it does not expire (pages_manage_posts, pages_manage_engagement, pages_read_engagement, read_insights).',
            ],
            SocialPlatform::TelegramChannel => [
                'id' => 'The channel: @channelname for a public channel, or its -100... ID for a private one.',
                'token' => 'The bot token from @BotFather. Add that bot to the channel as an admin allowed to post.',
            ],
            default => [
                'id' => 'The platform\'s own ID for this account.',
                'token' => 'Access token for this account.',
            ],
        };
    }
}

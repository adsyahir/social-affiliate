<?php

namespace App\Social;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Social\Contracts\SocialPublisher;
use App\Social\Exceptions\UnsupportedPlatformException;
use App\Social\Facebook\FacebookPagePublisher;
use App\Social\Telegram\TelegramChannelPublisher;
use App\Social\Threads\ThreadsPublisher;

class SocialPublisherFactory
{
    /**
     * @throws UnsupportedPlatformException
     */
    public function make(SocialAccount $account): SocialPublisher
    {
        $driver = self::driver($account->platform) ?? throw UnsupportedPlatformException::for($account->platform);

        return app($driver, ['account' => $account]);
    }

    public static function supports(SocialPlatform $platform): bool
    {
        return self::driver($platform) !== null;
    }

    public static function supportsInsights(SocialPlatform $platform): bool
    {
        $driver = self::driver($platform);

        return $driver !== null && $driver::hasInsights();
    }

    /**
     * @return class-string<SocialPublisher>|null
     */
    private static function driver(SocialPlatform $platform): ?string
    {
        return match ($platform) {
            SocialPlatform::Threads => ThreadsPublisher::class,
            SocialPlatform::FacebookPage => FacebookPagePublisher::class,
            SocialPlatform::TelegramChannel => TelegramChannelPublisher::class,
            SocialPlatform::Instagram, SocialPlatform::LinkedIn, SocialPlatform::X, SocialPlatform::TikTok => null,
        };
    }
}

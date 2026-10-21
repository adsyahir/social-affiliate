<?php

namespace App\Affiliates;

use App\Affiliates\Amazon\AmazonProvider;
use App\Affiliates\Contracts\AffiliateProvider;
use App\Affiliates\Exceptions\UnsupportedProgramException;
use App\Affiliates\Shopee\ShopeeProvider;
use App\Enums\AffiliateProgram;
use App\Models\AffiliateAccount;

class AffiliateProviderFactory
{
    /**
     * @throws UnsupportedProgramException
     */
    public function make(AffiliateAccount $account): AffiliateProvider
    {
        $driver = self::driverFor($account->program);

        return app($driver, ['account' => $account]);
    }

    public static function supports(AffiliateProgram $program): bool
    {
        return self::driver($program) !== null;
    }

    /**
     * @return array<string, string>
     */
    public static function credentialFields(AffiliateProgram $program): array
    {
        $driver = self::driver($program);

        return $driver === null ? [] : $driver::credentialFields();
    }

    /**
     * @return class-string<AffiliateProvider>
     *
     * @throws UnsupportedProgramException
     */
    private static function driverFor(AffiliateProgram $program): string
    {
        return self::driver($program) ?? throw UnsupportedProgramException::for($program);
    }

    /**
     * @return class-string<AffiliateProvider>|null
     */
    private static function driver(AffiliateProgram $program): ?string
    {
        return match ($program) {
            AffiliateProgram::Shopee => ShopeeProvider::class,
            AffiliateProgram::Amazon => AmazonProvider::class,
            AffiliateProgram::TikTokShop => null,
        };
    }
}

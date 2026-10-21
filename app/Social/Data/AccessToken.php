<?php

namespace App\Social\Data;

use Carbon\CarbonImmutable;
use SensitiveParameter;

final readonly class AccessToken
{
    public function __construct(
        #[SensitiveParameter] public string $token,
        public ?CarbonImmutable $expiresAt,
    ) {}
}

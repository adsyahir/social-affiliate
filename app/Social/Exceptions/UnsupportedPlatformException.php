<?php

namespace App\Social\Exceptions;

use App\Enums\SocialPlatform;

class UnsupportedPlatformException extends SocialException
{
    public static function for(SocialPlatform $platform): self
    {
        return new self("There is no driver for {$platform->getLabel()} yet.");
    }
}

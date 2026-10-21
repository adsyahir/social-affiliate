<?php

namespace App\Affiliates\Exceptions;

use App\Enums\AffiliateProgram;

class UnsupportedProgramException extends AffiliateException
{
    public static function for(AffiliateProgram $program): self
    {
        return new self("There is no driver for {$program->getLabel()} yet.");
    }
}

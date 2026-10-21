<?php

namespace App\Enums;

enum ReplyStatus: string
{
    case New = 'new';
    case Drafted = 'drafted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Replied = 'replied';
    case Ignored = 'ignored';
}

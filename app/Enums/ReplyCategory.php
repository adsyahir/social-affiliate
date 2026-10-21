<?php

namespace App\Enums;

enum ReplyCategory: string
{
    case Question = 'question';
    case Praise = 'praise';
    case Complaint = 'complaint';
    case Spam = 'spam';
    case Other = 'other';
}

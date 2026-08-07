<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case Completed = 'completed';
    case Spam = 'spam';
}

<?php

namespace App\Enums;

enum FormStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}

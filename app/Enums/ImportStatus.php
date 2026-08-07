<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Preview = 'preview';
    case Committed = 'committed';
    case Failed = 'failed';
}

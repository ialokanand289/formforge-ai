<?php

namespace App\Enums;

enum ImportSource: string
{
    case Docx = 'docx';
    case Xlsx = 'xlsx';
}

<?php

namespace App\Enums;

enum GenerationType: string
{
    case Generate = 'generate';
    case Edit = 'edit';
    case ImportInfer = 'import_infer';
}

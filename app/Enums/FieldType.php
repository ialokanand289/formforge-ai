<?php

namespace App\Enums;

enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Email = 'email';
    case Phone = 'phone';
    case Date = 'date';
    case Dropdown = 'dropdown';
    case Radio = 'radio';
    case Checkbox = 'checkbox';
    case File = 'file';
    case Heading = 'heading';
    case Rating = 'rating';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function requiresOptions(): bool
    {
        return in_array($this, [self::Dropdown, self::Radio, self::Checkbox], true);
    }

    public function isSubmittable(): bool
    {
        return $this !== self::Heading;
    }
}

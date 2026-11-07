<?php

declare(strict_types=1);

namespace App\Enums;

enum SettingType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Url = 'url';
    case Email = 'email';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case List = 'list';
}

<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * CMS Markdown to HTML. Raw HTML in the source is stripped and javascript:
 * style links are dropped, so admin-entered text can be printed unescaped.
 */
final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]);
    }
}

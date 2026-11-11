<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SettingKey;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The approved legal texts (privacy, terms, refund policy) as CMS page
 * content. Source: `database/data/legal/{slug}.md`, an exact copy of
 * `docs/legal/` (docs aren't deployed; a test keeps the copies equal).
 *
 * Each file is "# Title", the "Last updated" line and an introduction,
 * then "## " sections. That becomes one text block for the introduction
 * and one per section (heading + Markdown), the page's CMS body format.
 * Consecutive plain lines (e.g. "Email: …" then "WhatsApp: …") keep
 * their line breaks.
 */
final class LegalPages
{
    public const SLUGS = ['privacy', 'terms', 'refund-policy'];

    /**
     * @return array{title: string, answer: string, meta_description: string, body: list<array{type: string, data: array{heading?: string, content: string}}>}
     */
    public static function content(string $slug): array
    {
        $lines = preg_split('/\R/', self::markdown($slug)) ?: [];
        $title = '';
        /** @var list<string|null> $headings section headings; null = the introduction */
        $headings = [null];
        /** @var list<list<string>> $sectionLines */
        $sectionLines = [[]];
        $current = 0;

        foreach ($lines as $line) {
            if ($title === '' && str_starts_with($line, '# ')) {
                $title = trim(substr($line, 2));

                continue;
            }

            if (str_starts_with($line, '## ')) {
                $headings[] = trim(substr($line, 3));
                $sectionLines[] = [];
                $current++;

                continue;
            }

            $sectionLines[$current][] = rtrim($line);
        }

        if ($title === '') {
            throw new RuntimeException('The legal page '.$slug.' has no "# " title.');
        }

        /** @var list<array{type: string, data: array{heading?: string, content: string}}> $body */
        $body = [];
        $answer = '';

        foreach ($headings as $i => $heading) {
            $content = self::keepLineBreaks($sectionLines[$i]);

            if ($content === '' && $heading === null) {
                continue;
            }

            if ($heading === null) {
                // The introduction: the first paragraph that isn't the "Last updated" line is the page answer.
                foreach (preg_split('/\n{2,}/', $content) ?: [] as $paragraph) {
                    if (! str_starts_with($paragraph, 'Last updated')) {
                        $answer = $paragraph;

                        break;
                    }
                }

                $body[] = ['type' => 'text', 'data' => ['content' => $content]];

                continue;
            }

            $body[] = ['type' => 'text', 'data' => ['heading' => $heading, 'content' => $content]];
        }

        return [
            'title' => $title,
            'answer' => $answer,
            'meta_description' => mb_strimwidth(preg_replace('/\s+/', ' ', strip_tags($answer)) ?? '', 0, 160, '…'),
            'body' => $body,
        ];
    }

    /** The refund policy as the `policies.refund` setting: the whole text below its title, Markdown. */
    public static function refundPolicySetting(): string
    {
        $markdown = self::markdown('refund-policy');

        return trim((string) preg_replace('/\A# [^\n]*\n/', '', $markdown));
    }

    /**
     * Fills `policies.refund` with the refund policy while it is empty (an
     * admin's own text is kept). Written straight to the settings table, as
     * the admin screen's 2,000-character Textarea limit doesn't apply here.
     *
     * @return bool whether it was filled
     */
    public static function fillRefundSetting(): bool
    {
        $key = SettingKey::RefundPolicy->value;
        $row = DB::table('settings')->where('key', $key)->first(['value']);
        $current = $row === null ? '' : json_decode((string) $row->value, true);

        if (is_string($current) && trim($current) !== '') {
            return false;
        }

        $value = json_encode(self::refundPolicySetting(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $row === null
            ? DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()])
            : DB::table('settings')->where('key', $key)->update(['value' => $value, 'updated_at' => now()]);

        app(Settings::class)->flush();

        return true;
    }

    public static function markdown(string $slug): string
    {
        if (! in_array($slug, self::SLUGS, true)) {
            throw new InvalidArgumentException('Unknown legal page: '.$slug);
        }

        $path = self::path($slug);
        $markdown = is_file($path) ? file_get_contents($path) : false;

        if ($markdown === false || trim($markdown) === '') {
            throw new RuntimeException('Missing legal text: '.$path);
        }

        return str_replace("\r\n", "\n", $markdown);
    }

    public static function path(string $slug): string
    {
        return database_path('data/legal/'.$slug.'.md');
    }

    /**
     * Trims the block and turns a single newline between two plain lines
     * (not list items, not blank) into a Markdown hard break.
     *
     * @param  list<string>  $lines
     */
    private static function keepLineBreaks(array $lines): string
    {
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }

        while ($lines !== [] && trim($lines[array_key_last($lines)]) === '') {
            array_pop($lines);
        }

        $out = [];

        foreach ($lines as $i => $line) {
            $next = $lines[$i + 1] ?? '';
            $plain = fn (string $l): bool => trim($l) !== '' && ! preg_match('/^\s*([-*+]|\d+\.)\s/', $l);

            $out[] = $plain($line) && $plain($next) ? $line.'  ' : $line;
        }

        return implode("\n", $out);
    }
}

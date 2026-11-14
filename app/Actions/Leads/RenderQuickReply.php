<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Models\Lead;
use App\Models\QuickReply;
use App\Support\BusinessFacts;
use App\Support\LeadRef;

/**
 * Fills a quick reply's tokens from live data at copy time (milestone 10c),
 * so prices and times always match the site:
 *
 * - the FAQ tokens of BusinessFacts::interpolate(): {schedule},
 *   {location}, {venue}, {map_link}, {trainer}, {cutoff}, {price:slug}, {per_session:slug},
 *   {validity:slug};
 * - {booking_link} (/book) and {product_link:slug} (/sessions/{slug}),
 *   absolute, with the signed lead reference when a lead is chosen and
 *   $tracked is on;
 * - {lead_name}: the lead's first name, or "there".
 *
 * Unknown tokens (e.g. a mistyped slug) stay as written so they're seen.
 */
final class RenderQuickReply
{
    public function __construct(private readonly BusinessFacts $facts) {}

    public function handle(QuickReply|string $reply, ?Lead $lead = null, bool $tracked = true): string
    {
        $body = $reply instanceof QuickReply ? $reply->body : $reply;
        $query = $lead !== null && $tracked ? LeadRef::query($lead) : [];

        $text = strtr($body, [
            '{lead_name}' => self::firstName($lead),
            '{booking_link}' => self::url(route('book'), $query),
        ]);

        $text = (string) preg_replace_callback(
            '/\{product_link:([a-z0-9-]+)\}/',
            fn (array $match): string => $this->facts->product($match[1]) === null
                ? $match[0]
                : self::url(route('sessions.show', $match[1]), $query),
            $text,
        );

        return $this->facts->interpolate($text);
    }

    /**
     * Tokens that would stay unfilled (unknown names or missing products).
     *
     * @return list<string>
     */
    public function unknownTokens(string $body): array
    {
        preg_match_all('/\{[a-z_]+(?::[a-z0-9-]+)?\}/', $this->handle($body), $matches);

        return array_values(array_unique($matches[0]));
    }

    private static function firstName(?Lead $lead): string
    {
        $name = trim((string) $lead?->name);

        return $name === '' ? 'there' : explode(' ', $name)[0];
    }

    /** @param  array<string, string>  $query */
    private static function url(string $url, array $query): string
    {
        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Tracking;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Marketing attribution for a lead: UTM parameters, click ids, landing URL
 * and referrer, plus when the visitor first arrived.
 *
 * The first-touch cookie (`rp_ft`, written by resources/js/tracking.js only
 * after the visitor accepts tracking) wins when present and valid. Without
 * it, the form's hidden fields (the current page's query string) and the
 * Referer header are used. Every value is trimmed and capped; anything
 * malformed is dropped, never trusted.
 */
final readonly class Attribution
{
    public const COOKIE = 'rp_ft';

    public const FIELDS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'fbclid',
        'gclid',
        'landing_url',
        'referrer',
    ];

    private const URL_FIELDS = ['landing_url', 'referrer'];

    /** A cookie bigger than this is ignored outright. */
    private const MAX_COOKIE_BYTES = 4096;

    /**
     * @param  array<string, string|null>  $fields  every key of FIELDS
     */
    private function __construct(
        public array $fields,
        public ?CarbonImmutable $firstTouchAt,
    ) {}

    /**
     * @param  array<string, mixed>  $values  keys from FIELDS; others ignored
     */
    public static function fromArray(array $values, ?CarbonImmutable $firstTouchAt = null): self
    {
        $fields = [];

        foreach (self::FIELDS as $field) {
            $fields[$field] = self::clean($field, $values[$field] ?? null);
        }

        return new self($fields, $firstTouchAt);
    }

    public static function none(): self
    {
        return self::fromArray([]);
    }

    /**
     * Cookie first; otherwise the form's hidden fields (the page the form
     * was on). The POST's own Referer is our page, so it is never used.
     */
    public static function fromRequest(Request $request): self
    {
        $cookie = self::fromCookie($request->cookies->get(self::COOKIE));

        if ($cookie !== null) {
            return $cookie;
        }

        $posted = [];

        foreach (self::FIELDS as $field) {
            $posted[$field] = $request->input($field);
        }

        return self::fromArray($posted);
    }

    /**
     * The first-touch cookie: JSON with FIELDS and `ts` (Unix seconds).
     * A timestamp in the future or more than a year old is dropped.
     */
    public static function fromCookie(mixed $raw): ?self
    {
        if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_COOKIE_BYTES) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        $firstTouch = null;
        $ts = $decoded['ts'] ?? null;

        if (is_int($ts) || (is_string($ts) && ctype_digit($ts))) {
            $candidate = CarbonImmutable::createFromTimestampUTC((int) $ts);

            if ($candidate->lte(now()->addMinutes(5)) && $candidate->gte(now()->subYear())) {
                $firstTouch = $candidate->min(now()->toImmutable());
            }
        }

        /** @var array<string, mixed> $decoded */
        $attribution = self::fromArray($decoded, $firstTouch);

        // A cookie with nothing usable in it is no cookie at all.
        return $attribution->isEmpty() && $firstTouch === null ? null : $attribution;
    }

    public function isEmpty(): bool
    {
        return array_filter($this->fields, fn (?string $value): bool => $value !== null) === [];
    }

    private static function clean(string $field, mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $isUrl = in_array($field, self::URL_FIELDS, true);
        $value = mb_substr(trim($value), 0, $isUrl ? 2000 : 255);

        if ($value === '') {
            return null;
        }

        if ($isUrl && ! preg_match('#^https?://#i', $value)) {
            return null;
        }

        // Control characters never belong in attribution values.
        return preg_match('/[\x00-\x1F\x7F]/', $value) ? null : $value;
    }
}

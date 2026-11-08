<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Lead;

/**
 * Signed lead reference for tracked DM links (milestone 10c):
 * `?ref=ig-dm&lead={id}-{tag}`. The tag is an HMAC of the id keyed with
 * the app key, so a link names one lead and can't be edited to point at
 * another. Anything unsigned, tampered or unknown resolves to null.
 */
final class LeadRef
{
    public const REF = 'ig-dm';

    private const TAG_LENGTH = 10;

    public static function for(Lead $lead): string
    {
        return $lead->id.'-'.self::tag($lead->id);
    }

    /** @return array{ref: string, lead: string} */
    public static function query(Lead $lead): array
    {
        return ['ref' => self::REF, 'lead' => self::for($lead)];
    }

    public static function resolveId(?string $value): ?int
    {
        if ($value === null || preg_match('/^(\d{1,10})-([a-z2-7]{'.self::TAG_LENGTH.'})$/', $value, $match) !== 1) {
            return null;
        }

        $id = (int) $match[1];

        return hash_equals(self::tag($id), $match[2]) ? $id : null;
    }

    public static function resolve(?string $value): ?Lead
    {
        $id = self::resolveId($value);

        return $id === null ? null : Lead::query()->find($id);
    }

    private static function tag(int $id): string
    {
        $hash = hash_hmac('sha256', 'lead-ref:'.$id, (string) config('app.key'), true);

        return substr(self::base32($hash), 0, self::TAG_LENGTH);
    }

    private static function base32(string $bytes): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }
}

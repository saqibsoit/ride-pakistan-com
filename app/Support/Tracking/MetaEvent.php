<?php

declare(strict_types=1);

namespace App\Support\Tracking;

use Carbon\CarbonImmutable;

/**
 * One Meta Conversions API event. `eventId` matches the browser Pixel's
 * eventID so Meta deduplicates the pair. Email and phone are normalised
 * and SHA-256 hashed here; they never leave the server in the clear.
 */
final readonly class MetaEvent
{
    public function __construct(
        public string $name,
        public string $eventId,
        public CarbonImmutable $time,
        public VisitorContext $visitor,
        public ?string $email = null,
        public ?string $phone = null,
        public ?int $valueMinor = null,
        public string $currency = 'PKR',
        public ?string $orderId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $userData = array_filter([
            'em' => $this->email === null ? null : [self::hash(mb_strtolower(trim($this->email)))],
            'ph' => self::phoneHash($this->phone),
            'fbp' => $this->visitor->fbp,
            'fbc' => $this->visitor->fbc,
            'client_ip_address' => $this->visitor->ip,
            'client_user_agent' => $this->visitor->userAgent,
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        $customData = array_filter([
            'currency' => $this->valueMinor === null ? null : $this->currency,
            // Meta wants major units; the conversion happens only here.
            'value' => $this->valueMinor === null ? null : round($this->valueMinor / 100, 2),
            'order_id' => $this->orderId,
        ], fn (mixed $value): bool => $value !== null);

        return array_filter([
            'event_name' => $this->name,
            'event_time' => $this->time->getTimestamp(),
            'event_id' => $this->eventId,
            'action_source' => 'website',
            'event_source_url' => $this->visitor->url,
            'user_data' => $userData,
            'custom_data' => $customData === [] ? null : $customData,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return list<string>|null */
    private static function phoneHash(?string $phone): ?array
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        // Local Pakistani numbers (03xx…) get the country code.
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = '92'.substr($digits, 1);
        }

        return [self::hash($digits)];
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Tracking;

use Illuminate\Http\Request;

/**
 * What Meta CAPI needs to match a server event to the browser one: IP,
 * user agent, the Pixel's `_fbp`/`_fbc` cookies and the page URL. Built
 * only for visitors who granted consent; never stored on the lead.
 */
final readonly class VisitorContext
{
    public function __construct(
        public ?string $ip,
        public ?string $userAgent,
        public ?string $fbp,
        public ?string $fbc,
        public ?string $url,
    ) {}

    public static function fromRequest(Request $request, ?string $url = null): self
    {
        return new self(
            ip: $request->ip(),
            userAgent: self::cap($request->userAgent(), 500),
            fbp: self::cookie($request, '_fbp'),
            fbc: self::cookie($request, '_fbc'),
            url: self::cap($url ?? $request->headers->get('referer') ?? $request->fullUrl(), 2000),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;

        return new self($string('ip'), $string('ua'), $string('fbp'), $string('fbc'), $string('url'));
    }

    /** @return array{ip: string|null, ua: string|null, fbp: string|null, fbc: string|null, url: string|null} */
    public function toArray(): array
    {
        return [
            'ip' => $this->ip,
            'ua' => $this->userAgent,
            'fbp' => $this->fbp,
            'fbc' => $this->fbc,
            'url' => $this->url,
        ];
    }

    /** Pixel cookies look like `fb.1.<ts>.<id>`; anything else is dropped. */
    private static function cookie(Request $request, string $name): ?string
    {
        $value = $request->cookies->get($name);

        return is_string($value) && preg_match('/^fb\.\d\.\d+\.[A-Za-z0-9_.-]{1,255}$/', $value) ? $value : null;
    }

    private static function cap(?string $value, int $length): ?string
    {
        return $value === null || $value === '' ? null : mb_substr($value, 0, $length);
    }
}

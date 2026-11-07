<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Enums\SettingKey;
use App\Support\Settings;

/**
 * robots.txt. Production allows everything public, names the reputable AI
 * crawlers we welcome (setting), and keeps private areas out. Every other
 * environment (staging, local) disallows all crawling.
 */
final class RobotsTxt
{
    /** Paths no crawler needs. The admin path is deliberately not listed. */
    private const PRIVATE_PATHS = ['/account', '/checkout', '/book', '/checkin', '/webhooks', '/login'];

    public function __construct(private readonly Settings $settings) {}

    public function render(): string
    {
        if (! Seo::siteIndexable()) {
            return "# Not a production site: please do not crawl.\nUser-agent: *\nDisallow: /\n";
        }

        $disallow = implode("\n", array_map(fn (string $path): string => 'Disallow: '.$path, self::PRIVATE_PATHS));

        $lines = ["User-agent: *\n".$disallow];

        foreach ($this->settings->list(SettingKey::AiCrawlers) as $agent) {
            $lines[] = "User-agent: {$agent}\nAllow: /\n".$disallow;
        }

        $lines[] = 'Sitemap: '.route('sitemap');

        return implode("\n\n", $lines)."\n";
    }
}

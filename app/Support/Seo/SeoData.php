<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Spatie\SchemaOrg\Type;

/**
 * Everything the <head> needs for one page. Built by App\Support\Seo\Seo.
 */
final readonly class SeoData
{
    /**
     * @param  list<Type>  $schema  JSON-LD nodes rendered as one @graph
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public string $robots,
        public string $image,
        public string $imageAlt,
        public string $ogType = 'website',
        public array $schema = [],
    ) {}

    public function jsonLd(): string
    {
        return JsonLd::encode($this->schema);
    }
}

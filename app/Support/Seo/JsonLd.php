<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Spatie\SchemaOrg\Type;

/**
 * Serialises schema nodes as a single JSON-LD @graph. Safe to print inside
 * a <script> tag: "<", ">" and "&" are escaped.
 */
final class JsonLd
{
    /**
     * @param  list<Type>  $nodes
     */
    public static function encode(array $nodes): string
    {
        $graph = array_map(static function (Type $node): array {
            $data = $node->toArray();
            unset($data['@context']);

            return $data;
        }, $nodes);

        return (string) json_encode(
            ['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR,
        );
    }
}

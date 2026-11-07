<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * "Design tokens from resources/css/tokens.css only." Views must not contain
 * raw colours, arbitrary-value utilities (text-[18px], bg-[#fff]) or inline
 * style attributes (which a strict CSP would also block).
 */

dataset('views', function (): array {
    $files = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/resources/views')->name('*.blade.php') as $file) {
        $files[$file->getRelativePathname()] = [$file->getRealPath()];
    }

    return $files;
});

it('uses design tokens only', function (string $path): void {
    $contents = (string) file_get_contents($path);

    expect($contents)
        ->not->toMatch('/(?<![\w&])#(?:[0-9a-fA-F]{3}){1,2}(?:[0-9a-fA-F]{2})?\b(?![\w-])/', 'raw hex colour')
        ->not->toMatch('/\b(?:rgba?|hsla?|oklch)\(/', 'raw colour function')
        ->not->toMatch('/[a-z0-9]-\[[^\]]+\]/', 'arbitrary-value utility')
        ->not->toMatch('/\sstyle="/', 'inline style attribute');
})->with('views');

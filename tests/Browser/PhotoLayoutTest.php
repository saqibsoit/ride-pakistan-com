<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;

/*
 * Image layout in a real browser. <x-img> renders <picture class="contents">
 * so the <img> is the grid or flex item; its <source> elements must stay out
 * of the layout, or Chrome gives each one a grid cell and pushes the images
 * along (the homepage photo band ended up stacked, offset to the right).
 */

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
});

/** The photo band's visible images: [x, y, width, height] each, page coordinates. */
const PHOTO_BAND_BOXES = <<<'JS'
    [...document.querySelectorAll('[data-photo-band] img')]
        .filter((img) => getComputedStyle(img).display !== 'none')
        .map((img) => {
            const r = img.getBoundingClientRect();
            return [Math.round(r.x), Math.round(r.y + scrollY), Math.round(r.width), Math.round(r.height)];
        })
    JS;

it('shows the homepage photo band as two side-by-side photos of equal height on desktop', function (): void {
    $page = visit('/')->on()->desktop();
    $browser = $page->page();

    $viewport = (int) $browser->evaluate('document.documentElement.clientWidth');
    /** @var list<array{int, int, int, int}> $boxes */
    $boxes = $browser->evaluate(PHOTO_BAND_BOXES);

    expect($boxes)->toHaveCount(2);
    [$wide, $narrow] = $boxes;

    // Full width, edge to edge, one row.
    expect($wide[0])->toBe(0)
        ->and($narrow[0] + $narrow[2])->toBe($viewport)
        ->and($narrow[1])->toBe($wide[1])
        ->and($narrow[0])->toBeGreaterThan($wide[0] + $wide[2]);

    // Same height, within the photo-band clamp (20rem to 42.5rem).
    expect($narrow[3])->toBe($wide[3])
        ->and($wide[3])->toBeGreaterThanOrEqual(320)->toBeLessThanOrEqual(680);

    // About 1.4 : 1.
    expect($wide[2] / $narrow[2])->toBeGreaterThan(1.35)->toBeLessThan(1.45);

    $page->assertNoJavaScriptErrors();
});

it('shows one full-width photo in the band on mobile', function (): void {
    $page = visit('/')->on()->mobile();
    $browser = $page->page();

    $viewport = (int) $browser->evaluate('document.documentElement.clientWidth');
    /** @var list<array{int, int, int, int}> $boxes */
    $boxes = $browser->evaluate(PHOTO_BAND_BOXES);

    expect($boxes)->toHaveCount(1)
        ->and($boxes[0][0])->toBe(0)
        ->and($boxes[0][2])->toBe($viewport)
        ->and($boxes[0][3])->toBeGreaterThanOrEqual(320);
});

it('keeps <source> elements out of grid layouts', function (): void {
    // Independent of generated image variants (CI has none, so the real
    // pages may render no <source> at all): build an <x-img>-shaped grid.
    $page = visit('/')->on()->desktop();

    $result = $page->page()->evaluate(<<<'JS'
        (() => {
            const grid = document.createElement('div');
            grid.className = 'grid grid-cols-2';
            grid.innerHTML = '<picture class="contents"><source type="image/avif" srcset="/a.avif"><source type="image/webp" srcset="/a.webp"><img src="/a.jpg" alt="" width="10" height="10"></picture><div>text</div>';
            document.body.append(grid);
            const img = grid.querySelector('img').getBoundingClientRect();
            const text = grid.querySelector('div').getBoundingClientRect();
            const out = { sameRow: Math.round(img.y) === Math.round(text.y), imgFirst: img.x < text.x, sourceDisplay: getComputedStyle(grid.querySelector('source')).display };
            grid.remove();
            return out;
        })()
        JS);

    expect($result)->toBe(['sameRow' => true, 'imgFirst' => true, 'sourceDisplay' => 'none']);
});

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;

/*
 * The floating WhatsApp button (prompt 14): bottom-right on every public
 * page, above the mobile booking bar on product pages, never over it.
 */

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
});

/** [x, y, width, height] of the button and of the visible booking bar (or null), viewport coordinates, plus the viewport size. */
const WHATSAPP_LAYOUT = <<<'JS'
    (() => {
        const box = (el) => {
            if (!el || getComputedStyle(el).display === 'none') return null;
            const r = el.getBoundingClientRect();
            return [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)];
        };
        return {
            button: box(document.querySelector('[data-whatsapp-button]')),
            bar: box(document.querySelector('.bottom-bar')),
            viewport: [document.documentElement.clientWidth, window.innerHeight],
            position: getComputedStyle(document.querySelector('[data-whatsapp-button]')).position,
        };
    })()
    JS;

it('sits in the bottom-right corner on desktop', function (): void {
    $page = visit('/sessions/single-session')->on()->desktop();
    /** @var array{button: list<int>, bar: list<int>|null, viewport: list<int>, position: string} $layout */
    $layout = $page->page()->evaluate(WHATSAPP_LAYOUT);
    [$x, $y, $w, $h] = $layout['button'];
    [$vw, $vh] = $layout['viewport'];

    expect($layout['position'])->toBe('fixed')
        ->and($layout['bar'])->toBeNull()
        ->and($w)->toBeGreaterThanOrEqual(48)->and($h)->toBeGreaterThanOrEqual(48)
        ->and($vw - ($x + $w))->toBeGreaterThanOrEqual(16)->toBeLessThanOrEqual(32)
        ->and($vh - ($y + $h))->toBeGreaterThanOrEqual(16)->toBeLessThanOrEqual(32);

    $page->assertNoJavaScriptErrors();
});

it('sits above the mobile booking bar without overlapping it', function (): void {
    $page = visit('/sessions/single-session')->on()->mobile();
    /** @var array{button: list<int>, bar: list<int>|null, viewport: list<int>, position: string} $layout */
    $layout = $page->page()->evaluate(WHATSAPP_LAYOUT);
    [$x, $y, $w, $h] = $layout['button'];
    [$vw] = $layout['viewport'];

    expect($layout['bar'])->not->toBeNull();
    [, $barTop] = $layout['bar'];

    expect($y + $h)->toBeLessThanOrEqual($barTop - 8)
        ->and($x + $w)->toBeLessThanOrEqual($vw)
        ->and($x)->toBeGreaterThan(0);

    $page->assertNoJavaScriptErrors();
});

it('keeps to the bottom corner on mobile pages without a booking bar', function (): void {
    $page = visit('/faq')->on()->mobile();
    /** @var array{button: list<int>, bar: list<int>|null, viewport: list<int>, position: string} $layout */
    $layout = $page->page()->evaluate(WHATSAPP_LAYOUT);
    [, $y, , $h] = $layout['button'];
    [, $vh] = $layout['viewport'];

    expect($layout['bar'])->toBeNull()
        ->and($vh - ($y + $h))->toBeGreaterThanOrEqual(16)->toBeLessThanOrEqual(32);
});

it('can be reached with the keyboard and names itself', function (): void {
    $page = visit('/faq')->on()->desktop();
    $browser = $page->page();

    $focused = $browser->evaluate(<<<'JS'
        (() => {
            const el = document.querySelector('[data-whatsapp-button]');
            el.focus();
            return [document.activeElement === el, el.tabIndex, el.getAttribute('aria-label'), getComputedStyle(el).outlineStyle];
        })()
        JS);

    expect($focused[0])->toBeTrue()
        ->and($focused[1])->toBe(0)
        ->and($focused[2])->toBe('Chat with us on WhatsApp (opens in a new tab)');
});

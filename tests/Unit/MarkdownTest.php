<?php

declare(strict_types=1);

use App\Support\Markdown;

it('renders Markdown', function (): void {
    expect(Markdown::toHtml("## Title\n\n- one\n- **two**"))
        ->toContain('<h2>Title</h2>')
        ->toContain('<li><strong>two</strong></li>');
});

it('strips raw HTML and unsafe links from CMS text', function (): void {
    $html = Markdown::toHtml("<script>alert(1)</script>\n\n[click](javascript:alert(1)) <img src=x onerror=alert(1)>");

    expect($html)
        ->not->toContain('<script')
        ->not->toContain('javascript:')
        ->not->toContain('onerror');
});

it('returns nothing for empty text', function (): void {
    expect(Markdown::toHtml("  \n"))->toBe('');
});

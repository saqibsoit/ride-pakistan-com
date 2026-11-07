<?php

declare(strict_types=1);

it('renders every component on the styleguide view', function (): void {
    $html = view('styleguide')->render();

    expect($html)
        ->toContain('<h1')
        ->toContain('Colours')
        ->toContain('Experience cards and filters')
        ->toContain('Selling fast')
        ->toContain('Fully booked')
        ->toContain('PKR 100,000')
        ->toContain('aria-label="Price and booking"')
        ->toContain('<details')
        ->toContain('role="alert"')
        ->toContain('aria-invalid="true"')
        ->toContain('aria-label="Breadcrumb"')
        ->toContain('noindex, nofollow');

    expect(substr_count($html, '<h1'))->toBe(1);
});

it('is not routed outside the local environment', function (): void {
    expect(app()->environment('local'))->toBeFalse();

    $this->get('/styleguide')->assertNotFound();
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('site', ['url' => '/images']);
});

it('renders a primary button as a link with an external note', function (): void {
    $this->blade('<x-button href="https://ridepakistan.co" external>Explore</x-button>')
        ->assertSee('href="https://ridepakistan.co"', false)
        ->assertSee('target="_blank"', false)
        ->assertSee('rel="noopener"', false)
        ->assertSee('(opens in a new tab)')
        ->assertSee('bg-sand', false);
});

it('renders a ghost button element with a type', function (): void {
    $this->blade('<x-button variant="ghost" type="submit" size="sm">Send</x-button>')
        ->assertSee('<button type="submit"', false)
        ->assertSee('bg-transparent', false)
        ->assertDontSee('href=', false);
});

it('renders badge variants', function (string $variant, string $class): void {
    $this->blade('<x-badge :variant="$variant">Label</x-badge>', ['variant' => $variant])
        ->assertSee($class, false)
        ->assertSee('Label');
})->with([
    ['neutral', 'border-line-strong'],
    ['featured', 'bg-sand'],
    ['selling-fast', 'bg-saddle'],
    ['price', 'bg-earth'],
    ['error', 'text-error'],
]);

it('formats money from minor units', function (): void {
    $this->blade('<x-money :minor="1000000" />')->assertSee('PKR 10,000');
});

it('renders a section heading at the requested level', function (): void {
    $this->blade('<x-section-heading title="Prices." eyebrow="Pricing" lede="Start small." level="1" id="p" />')
        ->assertSeeInOrder(['<h1', 'id="p"', 'Prices.</h1>'], false)
        ->assertSee('Pricing')
        ->assertSee('Start small.');
});

it('renders a fact strip as a description list', function (): void {
    $this->blade('<x-fact-strip label="Facts"><x-fact value="2 hours" label="Per session" /></x-fact-strip>')
        ->assertSee('class="wrap fact-strip"', false)->assertSee('aria-label="Facts"', false)
        ->assertSeeInOrder(['<dt', 'Per session', '<dd', '2 hours'], false);
});

it('renders a numbered step list', function (): void {
    $this->blade('<x-step-list><x-step title="Groom">Brush him down.</x-step><x-step title="Ride">Trail.</x-step></x-step-list>')
        ->assertSee('<ol class="step-list">', false)
        ->assertSeeInOrder(['<h3', 'Groom', 'Brush him down.', 'Ride'], false);
});

it('renders a price row with formatted price and featured style', function (): void {
    $this->blade('<x-price-row title="16-class package" :amount-minor="10000000" caption="valid 10 weeks" summary="16 sessions." label="Best value" featured>Body</x-price-row>')
        ->assertSee('PKR 100,000')
        ->assertSee('valid 10 weeks')
        ->assertSee('Best value')
        ->assertSee('price-row-featured', false);
});

it('renders an FAQ item as a details disclosure', function (): void {
    $this->blade('<x-faq-item question="What should I wear?" group="faq">Boots.</x-faq-item>')
        ->assertSeeInOrder(['<details', 'name="faq"'], false)
        ->assertSee('<summary', false)
        ->assertSee('What should I wear?')
        ->assertSee('Boots.');
});

it('renders a breadcrumb with the current page marked', function (): void {
    $this->blade('<x-breadcrumb :items="$items" />', ['items' => [
        ['label' => 'Home', 'url' => '/'],
        ['label' => 'Experiences', 'url' => '/experiences'],
        ['label' => 'Single session'],
    ]])
        ->assertSee('aria-label="Breadcrumb"', false)
        ->assertSee('href="/experiences"', false)
        ->assertSee('aria-current="page"', false)
        ->assertSee('Single session');
});

it('renders alerts with the right role and a non-colour label', function (string $variant, string $role, string $label): void {
    $this->blade('<x-alert :variant="$variant" title="Heads up">Body</x-alert>', ['variant' => $variant])
        ->assertSee('role="'.$role.'"', false)
        ->assertSee($label.':')
        ->assertSee('aria-hidden="true"', false);
})->with([
    ['info', 'status', 'Note'],
    ['success', 'status', 'Success'],
    ['warning', 'status', 'Warning'],
    ['error', 'alert', 'Error'],
]);

it('renders filter chips as links or toggle buttons', function (): void {
    $this->blade('<x-filter-chips label="Filter"><x-filter-chip href="/x" active>All</x-filter-chip><x-filter-chip :count="2">Lessons</x-filter-chip></x-filter-chips>')
        ->assertSee('role="group" aria-label="Filter"', false)
        ->assertSee('aria-current="true"', false)
        ->assertSee('aria-pressed="false"', false)
        ->assertSee('(2)');
});

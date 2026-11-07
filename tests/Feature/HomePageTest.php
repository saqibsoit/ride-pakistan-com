<?php

declare(strict_types=1);

it('shows the placeholder home page', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Ride Pakistan')
        ->assertSee('<h1', false);
});

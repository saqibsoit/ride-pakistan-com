<?php

declare(strict_types=1);

use App\Models\User;

it('serves the admin panel at the configured path', function (): void {
    expect(config('app.admin_path'))->toBe('test-admin');

    $this->get('/test-admin/login')
        ->assertOk()
        ->assertSee('Sign in');
});

it('redirects guests from the admin panel to its login page', function (): void {
    $this->get('/test-admin')
        ->assertRedirect('/test-admin/login');
});

it('does not serve the admin panel at the default path', function (): void {
    $this->get('/admin/login')->assertNotFound();
});

it('denies panel access to every user until roles exist', function (): void {
    $user = User::factory()->make();

    $this->actingAs($user)
        ->get('/test-admin')
        ->assertForbidden();
});

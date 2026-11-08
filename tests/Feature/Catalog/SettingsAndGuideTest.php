<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Models\User;

it('explains every setting', function (SettingKey $key): void {
    expect(trim($key->help()))->not->toBe('')
        ->and(mb_strlen($key->help()))->toBeGreaterThan(20);
})->with(SettingKey::cases());

it('shows the help text on the settings page', function (): void {
    $this->actingAs(User::factory()->admin()->withMfa()->create())
        ->get('/test-admin/manage-settings')
        ->assertOk()
        ->assertSee(SettingKey::TransferHoldHours->help(), escape: true)
        ->assertSee(SettingKey::MapPinUrl->help(), escape: true);
});

it('shows the admin guide to the team', function (string $role): void {
    $this->actingAs(User::factory()->{$role}()->withMfa()->create())
        ->get('/test-admin/admin-guide')
        ->assertOk()
        ->assertSee('Add a new package')
        ->assertSee('Change seasonal timings')
        ->assertSee('Add a one-off session')
        ->assertSee('Hide, archive or delete a product');
})->with(['admin', 'staff']);

it('keeps the admin guide away from customers', function (): void {
    $this->actingAs(User::factory()->create())->get('/test-admin/admin-guide')->assertForbidden();
});

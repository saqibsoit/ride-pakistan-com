<?php

declare(strict_types=1);

use App\Http\Controllers\TransferProofController;
use App\Support\TransferProofs;

it('keeps transfer proofs on a private disk outside the web root, with no URL', function (): void {
    $disk = (array) config('filesystems.disks.'.TransferProofs::DISK);
    $root = str_replace('\\', '/', (string) $disk['root']);
    $public = str_replace('\\', '/', public_path());

    expect($disk['visibility'])->toBe('private')
        ->and($disk['serve'] ?? false)->toBeFalse()
        ->and($disk)->not->toHaveKey('url')
        ->and(str_starts_with($root, $public))->toBeFalse();

    // The only public symlink is storage/app/public, never the proofs.
    foreach ((array) config('filesystems.links') as $target) {
        expect(str_starts_with(str_replace('\\', '/', (string) $target), $root))->toBeFalse();
    }
});

it('never serves a proof file by a guessed path', function (): void {
    $this->get('/storage/transfers/x.jpg')->assertNotFound();
    $this->get('/storage/x.jpg')->assertNotFound();
    $this->get('/transfers/x.jpg')->assertNotFound();
    $this->get('/storage/app/transfers/x.jpg')->assertNotFound();
});

it('signs proof URLs for five minutes at most', function (): void {
    expect(TransferProofController::URL_MINUTES)->toBeLessThanOrEqual(5);
});

it('ships with debug off and secrets out of the repository', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toContain('APP_DEBUG=false')
        ->and($example)->toMatch('/^APP_KEY=$/m')
        ->and(file_exists(public_path('.env')))->toBeFalse();

    // .env is ignored by git.
    expect((string) file_get_contents(base_path('.gitignore')))->toMatch('/^\.env$/m');
});

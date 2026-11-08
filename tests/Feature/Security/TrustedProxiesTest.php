<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/_test/client', fn (Request $request): string => $request->ip().'|'.($request->secure() ? 'https' : 'http').'|'.$request->getHost());
});

it('takes the visitor IP and HTTPS from Cloudflare', function (string $cloudflareIp): void {
    $this->withServerVariables(['REMOTE_ADDR' => $cloudflareIp])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->get('/_test/client')
        ->assertOk()
        ->assertSeeText('203.0.113.9|https');
})->with(['IPv4' => '172.64.10.20', 'IPv6' => '2606:4700::6810:84e5']);

it('uses the IP Cloudflare appended, not one the visitor made up', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '162.158.1.1'])
        ->withHeaders(['X-Forwarded-For' => '10.0.0.1, 203.0.113.9'])
        ->get('/_test/client')
        ->assertSeeText('203.0.113.9|');
});

it('ignores forwarded headers from anywhere else', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->get('/_test/client')
        ->assertSeeText('198.51.100.7|http');
});

it('never trusts X-Forwarded-Host', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '172.64.10.20'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Host' => 'evil.example'])
        ->get('/_test/client')
        ->assertDontSeeText('evil.example');
});

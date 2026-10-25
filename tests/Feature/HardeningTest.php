<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

it('sends security headers on every response', function () {
    $response = $this->getJson('/api/v1/health');

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('Strict-Transport-Security'))->toBeNull();
});

it('sends security headers on errors too', function () {
    $this->getJson('/api/v1/nope')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('sets HSTS on https requests', function () {
    $this->getJson('https://localhost/api/v1/health')->assertHeader('Strict-Transport-Security');
});

it('trusts the proxy headers so urls are https', function () {
    Route::get('/api/v1/_url-probe', fn () => ['url' => url('/x'), 'secure' => request()->isSecure()]);

    $this->getJson('/api/v1/_url-probe', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'api.loomora.test',
        'X-Forwarded-Port' => '443',
    ])->assertOk()->assertExactJson(['url' => 'https://api.loomora.test/x', 'secure' => true]);
});

it('returns json for unknown api paths even without an Accept header', function () {
    $this->get('/api/v1/nope')->assertNotFound()->assertHeader('Content-Type', 'application/json');
});

it('returns json 405 for a wrong method', function () {
    $this->call('DELETE', '/api/v1/health')->assertStatus(405)->assertHeader('Content-Type', 'application/json');
});

it('hides details of unexpected errors when debug is off', function () {
    config(['app.debug' => false]);
    Route::get('/api/v1/_boom', fn () => throw new RuntimeException('secret db password'));

    $response = $this->get('/api/v1/_boom')->assertStatus(500)->assertHeader('Content-Type', 'application/json');

    expect($response->getContent())->not->toContain('secret db password')
        ->and($response->getContent())->not->toContain('trace')
        ->and($response->getContent())->not->toContain('RuntimeException');
});

it('keeps the plain health check cheap and public', function () {
    DB::shouldReceive('select')->never();

    $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('checks the database on a deep health check', function () {
    $this->getJson('/api/v1/health?deep=1')->assertOk()->assertExactJson(['status' => 'ok', 'database' => 'ok']);
});

it('reports 503 without details when the database is down', function () {
    DB::shouldReceive('select')->once()->andThrow(new PDOException('connection to 10.0.0.5 refused'));

    $response = $this->getJson('/api/v1/health?deep=1')->assertStatus(503)
        ->assertExactJson(['status' => 'error', 'database' => 'down']);

    expect($response->getContent())->not->toContain('10.0.0.5');
});

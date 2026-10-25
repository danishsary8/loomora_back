<?php

beforeEach(function () {
    config(['cors.allowed_origins' => ['https://app.loomora.test', 'https://preview.loomora.test']]);
});

function preflight(string $origin, string $path = '/api/v1/todos')
{
    return test()->call('OPTIONS', $path, [], [], [], [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
    ]);
}

function corsConfigFor(string $frontendUrl): array
{
    $before = $_SERVER['FRONTEND_URL'] ?? null;
    $_SERVER['FRONTEND_URL'] = $frontendUrl;

    try {
        return require config_path('cors.php');
    } finally {
        if ($before === null) {
            unset($_SERVER['FRONTEND_URL']);
        } else {
            $_SERVER['FRONTEND_URL'] = $before;
        }
    }
}

it('answers the preflight of an allowed origin', function () {
    $response = preflight('https://app.loomora.test');

    $response->assertNoContent();
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.loomora.test')
        ->and($response->headers->get('Access-Control-Allow-Methods'))->toContain('POST')
        ->and(strtolower($response->headers->get('Access-Control-Allow-Headers')))->toContain('authorization')
        ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBeNull();
});

it('allows every origin in a comma-separated list', function () {
    expect(preflight('https://preview.loomora.test')->headers->get('Access-Control-Allow-Origin'))
        ->toBe('https://preview.loomora.test');
});

it('does not answer the preflight of another origin', function () {
    $response = preflight('https://evil.example');

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull()
        ->and($response->headers->get('Access-Control-Allow-Methods'))->toBeNull();
});

it('does not match an origin that only starts like an allowed one', function () {
    expect(preflight('https://app.loomora.test.evil.example')->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('adds the allow-origin header to a real request from an allowed origin only', function () {
    $ok = $this->getJson('/api/v1/health', ['Origin' => 'https://app.loomora.test']);
    $blocked = $this->getJson('/api/v1/health', ['Origin' => 'https://evil.example']);

    expect($ok->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.loomora.test')
        ->and($blocked->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('allows no origin when FRONTEND_URL is empty', function () {
    $config = corsConfigFor('');

    expect($config['allowed_origins'])->toBe([]);
});

it('reads comma-separated FRONTEND_URL values and trims them', function () {
    $config = corsConfigFor('https://a.example/, https://b.example ,');

    expect($config['allowed_origins'])->toBe(['https://a.example', 'https://b.example'])
        ->and($config['paths'])->toBe(['api/*'])
        ->and($config['supports_credentials'])->toBeFalse();
});

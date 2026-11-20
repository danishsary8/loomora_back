<?php

use Symfony\Component\Process\Process;

function withIsolatedFrontendConfigCache(Closure $test): void
{
    $cache = dirname(__DIR__, 2).'/bootstrap/cache/frontend-origin-'.bin2hex(random_bytes(8)).'.php';

    try {
        $test($cache);
    } finally {
        if (is_file($cache)) {
            unlink($cache);
        }
    }
}

function frontendOriginProcess(array $arguments, string $origin, string $cache): Process
{
    $process = new Process([PHP_BINARY, ...$arguments], dirname(__DIR__, 2), [
        'APP_CONFIG_CACHE' => 'bootstrap/cache/'.basename($cache),
        'APP_DEBUG' => 'true',
        'FRONTEND_URL' => $origin,
        'LOG_CHANNEL' => 'null',
    ]);
    $process->run();

    return $process;
}

function frontendOriginResponses(string $origin, string $cache, string $requestOrigin): array
{
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $callback = $kernel->handle(Illuminate\Http\Request::create('/api/v1/auth/google/callback'));
        $health = $kernel->handle(Illuminate\Http\Request::create('/api/v1/health', server: ['HTTP_ORIGIN' => $argv[1]]));
        echo json_encode([
            'callbackStatus' => $callback->getStatusCode(),
            'callbackLocation' => $callback->headers->get('Location'),
            'callbackError' => $callback->getStatusCode() === 500 ? json_decode($callback->getContent(), true) : null,
            'allowedOrigin' => $health->headers->get('Access-Control-Allow-Origin'),
        ], JSON_THROW_ON_ERROR);
        PHP;

    $process = frontendOriginProcess(['-r', $script, '--', $requestOrigin], $origin, $cache);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('rejects an invalid frontend origin before writing the configuration cache', function (string $origin) {
    withIsolatedFrontendConfigCache(function (string $cache) use ($origin) {
        $process = frontendOriginProcess(['artisan', 'config:cache', '--no-ansi'], $origin, $cache);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toContain('FRONTEND_URL must be one canonical frontend origin.')
            ->and(is_file($cache))->toBeFalse();
    });
})->with([
    'multiple origins' => 'https://one.example,https://two.example',
    'malformed URL' => 'https://',
    'missing scheme' => 'one.example',
    'path' => 'https://one.example/app',
    'trailing slash' => 'https://one.example/',
    'query' => 'https://one.example?next=home',
    'empty query' => 'https://one.example?',
    'fragment' => 'https://one.example#home',
    'empty fragment' => 'https://one.example#',
    'unsupported scheme' => 'ftp://one.example',
    'non-local HTTP' => 'http://one.example',
    'userinfo' => 'https://fixture:secret@one.example',
    'wildcard' => 'https://*.example',
    'invalid port' => 'https://one.example:65536',
    'default port' => 'https://one.example:443',
    'non-canonical IPv4' => 'https://127.1',
    'invalid IDNA label' => 'https://xn--a.example',
    'uppercase host' => 'https://One.example',
    'empty' => '',
    'blank' => '   ',
]);

it('returns a generic server error instead of a malformed Google callback redirect even with debug enabled', function () {
    withIsolatedFrontendConfigCache(function (string $cache) {
        $response = frontendOriginResponses('https://one.example,https://two.example', $cache, 'https://one.example');

        expect($response['callbackStatus'])->toBe(500)
            ->and($response['callbackLocation'])->toBeNull()
            ->and($response['callbackError'])->toBe(['message' => 'Server Error'])
            ->and($response['allowedOrigin'])->toBeNull();
    });
});

it('uses the same frontend origin for a Google callback and CORS after caching configuration', function (string $origin) {
    withIsolatedFrontendConfigCache(function (string $cache) use ($origin) {
        $process = frontendOriginProcess(['artisan', 'config:cache', '--no-ansi'], $origin, $cache);
        expect($process->isSuccessful())->toBeTrue($process->getOutput())
            ->and(is_file($cache))->toBeTrue();

        $response = frontendOriginResponses('https://different.example', $cache, $origin);
        expect($response['callbackStatus'])->toBe(302)
            ->and($response['callbackLocation'])->toBe($origin.'/auth/google/callback?error=google_failed')
            ->and($response['allowedOrigin'])->toBe($origin);
    });
})->with([
    'production' => 'https://loomora-craft.vercel.app',
    'HTTPS with a non-default port' => 'https://staging.example:8443',
    'IDNA hostname' => 'https://xn--bcher-kva.example',
    'IPv6 with an IPv4-mapped address' => 'https://[::ffff:c000:201]',
    'IPv6 with an IPv4-compatible address' => 'https://[::c000:201]',
    'localhost' => 'http://localhost:5173',
    'IPv4 loopback' => 'http://127.0.0.1:5173',
    'IPv6 loopback' => 'http://[::1]:5173',
]);

it('derives CORS from the canonical origin even when the cache contains a previous allowlist', function () {
    withIsolatedFrontendConfigCache(function (string $cache) {
        $origin = 'https://loomora-craft.vercel.app';
        $other = 'https://preview.example';
        $process = frontendOriginProcess(['artisan', 'config:cache', '--no-ansi'], $origin, $cache);
        expect($process->isSuccessful())->toBeTrue($process->getOutput());

        $cached = require $cache;
        $cached['cors']['allowed_origins'] = [$other];
        file_put_contents($cache, '<?php return '.var_export($cached, true).';');

        expect(frontendOriginResponses($origin, $cache, $other)['allowedOrigin'])->toBeNull();
    });
});

it('rejects an invalid origin stored in an old config cache', function () {
    withIsolatedFrontendConfigCache(function (string $cache) {
        $origin = 'https://loomora-craft.vercel.app';
        $process = frontendOriginProcess(['artisan', 'config:cache', '--no-ansi'], $origin, $cache);
        expect($process->isSuccessful())->toBeTrue($process->getOutput());

        $cached = require $cache;
        $cached['app']['frontend_url'] = 'https://one.example,https://two.example';
        file_put_contents($cache, '<?php return '.var_export($cached, true).';');

        $response = frontendOriginResponses($origin, $cache, $origin);
        expect($response['callbackStatus'])->toBe(500)
            ->and($response['callbackLocation'])->toBeNull()
            ->and($response['callbackError'])->toBe(['message' => 'Server Error'])
            ->and($response['allowedOrigin'])->toBeNull();
    });
});

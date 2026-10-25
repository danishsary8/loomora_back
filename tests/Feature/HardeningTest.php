<?php

use Illuminate\Support\Facades\DB;

describe('CORS', function () {
    beforeEach(function () {
        config(['cors.allowed_origins' => ['https://app.loomora.test', 'https://preview.loomora.test']]);
    });

    it('lets the frontend origin through a preflight', function () {
        $this->call('OPTIONS', '/api/v1/todos', server: [
            'HTTP_ORIGIN' => 'https://app.loomora.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.loomora.test')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    });

    it('accepts every origin listed in FRONTEND_URL', function () {
        $this->getJson('/api/v1/health', ['Origin' => 'https://preview.loomora.test'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://preview.loomora.test');
    });

    it('does not allow any other origin', function () {
        $this->call('OPTIONS', '/api/v1/todos', server: [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->getJson('/api/v1/health', ['Origin' => 'https://evil.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    });

    it('reads a comma-separated FRONTEND_URL', function () {
        $before = [$_SERVER['FRONTEND_URL'] ?? null, $_ENV['FRONTEND_URL'] ?? null];
        $_SERVER['FRONTEND_URL'] = $_ENV['FRONTEND_URL'] = 'https://a.test/, https://b.test';
        $config = require config_path('cors.php');
        [$_SERVER['FRONTEND_URL'], $_ENV['FRONTEND_URL']] = $before;

        expect($config['allowed_origins'])->toBe(['https://a.test', 'https://b.test'])
            ->and($config['supports_credentials'])->toBeFalse()
            ->and($config['paths'])->toBe(['api/*']);
    });
});

describe('security headers and proxies', function () {
    it('sends hardening headers on API responses', function () {
        $this->getJson('/api/v1/health')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('Strict-Transport-Security');
    });

    it('trusts the proxy so HTTPS is detected and HSTS is sent', function () {
        $this->getJson('/api/v1/health', ['X-Forwarded-Proto' => 'https'])
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    });

    it('returns JSON without a stack trace when debug is off', function () {
        config(['app.debug' => false]);

        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('exception');
    });
});

describe('health', function () {
    it('answers cheaply without touching the database', function () {
        DB::shouldReceive('select')->never();

        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
    });

    it('checks the database with ?deep=1', function () {
        $this->getJson('/api/v1/health?deep=1')->assertOk()->assertExactJson(['status' => 'ok', 'database' => 'ok']);
    });

    it('returns 503 when the database is down', function () {
        DB::shouldReceive('select')->once()->andThrow(new RuntimeException('connection refused'));

        $this->getJson('/api/v1/health?deep=1')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'error', 'database' => 'unavailable']);
    });
});

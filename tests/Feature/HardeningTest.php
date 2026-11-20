<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

describe('CORS', function () {
    it('lets the frontend origin through a preflight', function () {
        $this->call('OPTIONS', '/api/v1/todos', server: [
            'HTTP_ORIGIN' => config('app.frontend_url'),
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', config('app.frontend_url'))
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    });

    it('allows the frontend origin on an actual request', function () {
        $this->getJson('/api/v1/health', ['Origin' => config('app.frontend_url')])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', config('app.frontend_url'))
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    });

    it('does not allow any other origin', function () {
        $this->call('OPTIONS', '/api/v1/todos', server: [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->getJson('/api/v1/health', ['Origin' => 'https://evil.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    });

    it('does not allow a lookalike origin with an extra host suffix', function () {
        $parts = parse_url(config('app.frontend_url'));
        $lookalike = $parts['scheme'].'://'.$parts['host'].'.example';

        $this->getJson('/api/v1/health', ['Origin' => $lookalike])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    });
});

describe('security headers and proxies', function () {
    it('adds security headers to HTTPS preflight responses', function () {
        $this->call('OPTIONS', '/api/v1/todos', server: [
            'HTTP_ORIGIN' => config('app.frontend_url'),
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', config('app.frontend_url'))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    });

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

    it('hides exception messages and details on an unhandled API error', function () {
        config(['app.debug' => false, 'logging.default' => 'null']);
        Route::get('/api/v1/test-error', function () {
            throw new RuntimeException('Internal diagnostic for this test');
        });

        $this->get('/api/v1/test-error')
            ->assertInternalServerError()
            ->assertExactJson(['message' => 'Server Error']);
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

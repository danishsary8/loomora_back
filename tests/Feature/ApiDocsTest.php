<?php

describe('live API reference', function () {
    it('serves the Scalar page with a CSP scoped to the pinned bundle', function () {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('Loomora API')
            ->assertSee(route('docs.spec'), false)
            ->assertSee('integrity="sha384-', false)
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader(
                'Content-Security-Policy',
                "default-src 'none'; script-src https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.78.0/; "
                ."style-src 'unsafe-inline'; img-src 'self' data:; font-src data:; connect-src 'self'; "
                ."base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            );
    });

    it('serves the OpenAPI contract as YAML', function () {
        $response = $this->get('/docs/openapi.yaml')->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith('application/yaml');
        expect(file_get_contents($response->baseResponse->getFile()->getPathname()))
            ->toStartWith('openapi: ');
    });

    it('keeps the strict API policy everywhere else', function () {
        $this->get('/docs/openapi.yaml')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    });
});

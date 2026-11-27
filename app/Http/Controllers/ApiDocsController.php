<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Live API reference: a Scalar page over docs/openapi.yaml, the contract shared with the frontend. */
class ApiDocsController extends Controller
{
    public function page(): View
    {
        return view('api-docs');
    }

    public function spec(): BinaryFileResponse
    {
        return response()->file(base_path('docs/openapi.yaml'), [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}

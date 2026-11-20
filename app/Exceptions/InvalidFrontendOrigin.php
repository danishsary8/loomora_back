<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class InvalidFrontendOrigin extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('FRONTEND_URL must be one canonical frontend origin.');
    }

    public function render(Request $request): Response
    {
        return $request->is('api/*') || $request->expectsJson()
            ? response()->json(['message' => 'Server Error'], 500)
            : response('Server Error', 500);
    }
}

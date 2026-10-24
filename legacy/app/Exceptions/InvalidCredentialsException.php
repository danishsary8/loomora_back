<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class InvalidCredentialsException extends ApiException
{
    public function __construct(string $message = 'The provided credentials are incorrect.')
    {
        parent::__construct(
            errorCode: 'invalid_credentials',
            message: $message,
            status: HttpResponse::HTTP_UNAUTHORIZED,
        );
    }
}

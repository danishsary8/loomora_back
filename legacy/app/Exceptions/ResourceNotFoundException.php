<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ResourceNotFoundException extends ApiException
{
    public function __construct(string $message = 'The requested resource was not found.')
    {
        parent::__construct(
            errorCode: 'not_found',
            message: $message,
            status: HttpResponse::HTTP_NOT_FOUND,
        );
    }
}

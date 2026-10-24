<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Raised when a third-party dependency (e.g. the Aladhan prayer-time API)
 * fails or returns an unusable payload.
 */
final class UpstreamServiceException extends ApiException
{
    public function __construct(
        string $message = 'An upstream service is currently unavailable.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            errorCode: 'upstream_unavailable',
            message: $message,
            status: HttpResponse::HTTP_SERVICE_UNAVAILABLE,
            previous: $previous,
        );
    }
}

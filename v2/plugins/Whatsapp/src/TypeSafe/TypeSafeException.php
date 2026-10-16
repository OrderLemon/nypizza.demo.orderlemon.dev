<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

use Pmsrapi\V2\Exception\ApiException;
use Throwable;

/**
 * Any TypeSafe failure: unreachable, rejected, overloaded, or an answer we
 * could not read. Callers catch this one type and fall back to Marvin.
 *
 * statusCode() is what this service would answer (502/503), not TypeSafe's
 * own status; that one is upstreamStatus(), 0 when TypeSafe was never reached.
 */
final class TypeSafeException extends ApiException
{
    public function __construct(
        string $message,
        string $errorCode,
        private readonly int $upstreamStatus = 0,
        int $statusCode = 502,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, ['upstream_status' => $upstreamStatus], $previous);
    }

    public static function notConfigured(): self
    {
        return new self(
            'TypeSafe is not configured: set typesafe.api_key in the secret config or TYPESAFE_API_KEY',
            'typesafe_not_configured',
            statusCode: 503,
        );
    }

    public function upstreamStatus(): int
    {
        return $this->upstreamStatus;
    }
}

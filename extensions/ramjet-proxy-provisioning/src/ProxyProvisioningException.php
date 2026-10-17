<?php

declare(strict_types=1);

namespace Ramjet\ProxyProvisioning;

use Illuminate\Http\Response;
use Pterodactyl\Exceptions\DisplayException;
use Throwable;

/**
 * A refusal the customer can act on or retry, rendered in the panel's standard error envelope with the status it names.
 * The original cause, when there is one, is kept as the previous exception so it is logged.
 */
final class ProxyProvisioningException extends DisplayException
{
    private function __construct(string $message, private readonly int $status, ?Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }

    public static function conflict(string $message, ?Throwable $previous = null): self
    {
        return new self($message, Response::HTTP_CONFLICT, $previous);
    }

    public static function unprocessable(string $message, ?Throwable $previous = null): self
    {
        return new self($message, Response::HTTP_UNPROCESSABLE_ENTITY, $previous);
    }

    public static function unavailable(string $message, ?Throwable $previous = null): self
    {
        return new self($message, Response::HTTP_SERVICE_UNAVAILABLE, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }
}

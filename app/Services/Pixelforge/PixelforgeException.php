<?php

namespace App\Services\Pixelforge;

use RuntimeException;
use Throwable;

class PixelforgeException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $payload  Decoded error body from the API, if any.
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?array $payload = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public function isClientError(): bool
    {
        return $this->status !== null && $this->status >= 400 && $this->status < 500;
    }

    public function isServerError(): bool
    {
        return $this->status !== null && $this->status >= 500;
    }

    public function isConnectionError(): bool
    {
        return $this->status === null;
    }
}

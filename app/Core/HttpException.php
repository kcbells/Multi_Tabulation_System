<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Thrown anywhere in a request to produce a JSON error with the given HTTP status. */
final class HttpException extends RuntimeException
{
    public function __construct(string $message, private int $status = 400)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}

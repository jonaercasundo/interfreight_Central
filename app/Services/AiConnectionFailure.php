<?php

namespace App\Services;

use RuntimeException;

class AiConnectionFailure extends RuntimeException
{
    public function __construct(public readonly string $category, string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

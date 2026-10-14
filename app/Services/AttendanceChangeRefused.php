<?php

namespace App\Services;

use RuntimeException;

/**
 * An attendance change, a request or a decision that was refused for a reason
 * the user can act on. The status is the HTTP status the caller answers with.
 */
class AttendanceChangeRefused extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}

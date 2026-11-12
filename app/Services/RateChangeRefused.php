<?php

namespace App\Services;

/**
 * A rate change that cannot be saved or decided. The message is written for the person
 * who tried, and is shown to them as it is.
 */
class RateChangeRefused extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}

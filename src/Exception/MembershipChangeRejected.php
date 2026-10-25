<?php

namespace App\Exception;

final class MembershipChangeRejected extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message
    ) {
        parent::__construct($message);
    }
}
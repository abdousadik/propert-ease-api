<?php
namespace App\Api;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApiProblem extends HttpException
{
    public function __construct(int $status, public readonly string $problemCode, string $message, public readonly array $details = [])
    {
        parent::__construct($status, $message);
    }
}

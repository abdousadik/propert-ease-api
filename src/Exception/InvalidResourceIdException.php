<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InvalidResourceIdException extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('Resource not found.');
    }
}

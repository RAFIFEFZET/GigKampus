<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CheckoutConflict extends ConflictHttpException
{
    public function __construct(
        string $message,
        public readonly bool $replaceCreationKey = false,
        public readonly bool $preserveBrief = false,
    ) {
        parent::__construct($message);
    }
}

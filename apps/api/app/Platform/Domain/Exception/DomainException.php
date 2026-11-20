<?php

declare(strict_types=1);

namespace App\Platform\Domain\Exception;

use RuntimeException;
use Throwable;

abstract class DomainException extends RuntimeException
{
    /** @param list<array{pointer: string, code: string, detail: string}> $fieldErrors */
    public function __construct(
        string $detail = '',
        public readonly array $fieldErrors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail, previous: $previous);
    }
}

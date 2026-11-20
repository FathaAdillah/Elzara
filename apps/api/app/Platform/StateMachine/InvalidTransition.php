<?php

declare(strict_types=1);

namespace App\Platform\StateMachine;

use App\Platform\Http\ApiProblem;
use RuntimeException;

/** Peta Doc 12 §4.3: 409 `state:transition-not-allowed`, tidak dapat diulang apa adanya. */
final class InvalidTransition extends RuntimeException
{
    public function __construct(
        public readonly Axis $axis,
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct("Transisi {$axis->value} '{$from}' -> '{$to}' tidak diizinkan graf Doc 01 §5.");
    }

    public function toProblem(): ApiProblem
    {
        return ApiProblem::make(
            category: 'state',
            kebabCode: 'transition-not-allowed',
            title: 'Transisi status tidak diizinkan',
            status: 409,
            code: 'STATE_TRANSITION_NOT_ALLOWED',
        )->withDetail($this->getMessage());
    }
}

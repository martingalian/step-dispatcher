<?php

declare(strict_types=1);

namespace StepDispatcher\States;

use StepDispatcher\Abstracts\StepStatus;

final class NotRunnable extends StepStatus
{
    public const VALUE = 'not_runnable';

    public function value(): string
    {
        return self::VALUE;
    }
}

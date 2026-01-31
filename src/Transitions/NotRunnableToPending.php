<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use Spatie\ModelStates\Transition;

final class NotRunnableToPending extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        return true;
    }

    public function handle(): Step
    {
        $this->step->state = new Pending($this->step);
        $this->step->save();

        info_if("[NotRunnableToPending.handle] Step ID {$this->step->id} transitioned to Pending");

        /*
        $this->step->logApplicationEvent(
            'Step transitioned to Pending',
            self::class,
            __FUNCTION__
        );
        */

        return $this->step;
    }
}

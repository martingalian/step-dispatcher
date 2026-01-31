<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use Spatie\ModelStates\Transition;

final class DispatchedToCancelled extends Transition
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
        $this->step->state = new Cancelled($this->step); // Apply the Cancelled state
        $this->step->save(); // Save the transition

        // Log after the state is saved
        info_if("[DispatchedToCancelled.handle] Step ID {$this->step->id} successfully transitioned to Cancelled");

        /*
        $this->step->logApplicationEvent(
            'Step successfully transitioned to Cancelled',
            self::class,
            __FUNCTION__
        );
        */

        return $this->step;
    }
}

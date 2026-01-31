<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use Spatie\ModelStates\Transition;

final class DispatchedToFailed extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        // Only allow transition if the current state is Running
        if (! ($this->step->state instanceof Dispatched)) {
            info_if("[RunningToFailed.canTransition] Step ID {$this->step->id} is not in Dispatched state, transition denied");

            return false;
        }

        return true;
    }

    public function handle(): Step
    {
        // Transition to Failed state
        $this->step->state = new Failed($this->step);
        $this->step->completed_at = now();
        $this->step->is_throttled = false; // Clear throttle flag - step is no longer waiting
        $this->step->save(); // Save the step after state transition

        info_if("[RunningToFailed.handle] Step ID {$this->step->id} successfully transitioned to Failed");

        /*
        $this->step->logApplicationEvent(
            'Step successfully transitioned to Failed',
            self::class,
            __FUNCTION__
        );
        */

        // Return the step for further processing if needed
        return $this->step;
    }
}

<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use Spatie\ModelStates\Transition;

final class RunningToPending extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        log_step($this->step->id, '→→→ RunningToPending::canTransition() called');
        log_step($this->step->id, 'Always returns TRUE (no restrictions)');
        return true;
    }

    public function handle(): Step
    {
        log_step($this->step->id, '╔═══════════════════════════════════════════════════════════╗');
        log_step($this->step->id, '║   RunningToPending::handle() - RETRY TRANSITION          ║');
        log_step($this->step->id, '╚═══════════════════════════════════════════════════════════╝');

        log_step($this->step->id, 'BEFORE INCREMENT:');
        log_step($this->step->id, '  - Current retries: '.$this->step->retries);
        log_step($this->step->id, '  - Current state: '.$this->step->state);
        log_step($this->step->id, '  - is_throttled flag: '.($this->step->is_throttled ? 'true' : 'false'));

        // Conditionally increment retry count based on is_throttled flag
        if ($this->step->is_throttled) {
            log_step($this->step->id, '⚠️ THROTTLE DETECTED - SKIPPING RETRY INCREMENT');
            log_step($this->step->id, '   → is_throttled = true');
            log_step($this->step->id, '   → This is a reschedule, not a retry');
            log_step($this->step->id, '   → retries will remain: '.$this->step->retries);
            info_if("[RunningToPending.handle] - Step ID {$this->step->id} is throttled - retries NOT incremented (staying at {$this->step->retries})");
        } else {
            log_step($this->step->id, '⚠️⚠️⚠️ THIS IS A REAL RETRY - INCREMENTING RETRIES ⚠️⚠️⚠️');
            log_step($this->step->id, 'Calling increment(\'retries\')...');
            $this->step->increment('retries');
            log_step($this->step->id, '✓ increment() completed');
            log_step($this->step->id, 'AFTER INCREMENT:');
            log_step($this->step->id, '  - New retries value: '.$this->step->retries);
            info_if("[RunningToPending.handle] - Step ID {$this->step->id} retries incremented to ".$this->step->retries);
        }

        /*
        $this->step->logApplicationEvent(
            'Step retries incremented to '.$this->step->retries,
            self::class,
            __FUNCTION__
        );
        */

        log_step($this->step->id, 'RESETTING TIMERS:');
        log_step($this->step->id, '  - Setting started_at = null');
        $this->step->started_at = null;
        log_step($this->step->id, '  - Setting completed_at = null');
        $this->step->completed_at = null;
        log_step($this->step->id, '  - Setting duration = 0');
        $this->step->duration = 0;
        log_step($this->step->id, '✓ Timers reset complete');

        // Reset the state to Pending
        log_step($this->step->id, 'CHANGING STATE:');
        log_step($this->step->id, '  - Old state: '.$this->step->state);
        log_step($this->step->id, '  - Creating new Pending state object...');
        $this->step->state = new Pending($this->step);
        log_step($this->step->id, '  - New state: '.$this->step->state);

        log_step($this->step->id, 'Calling save() to persist changes...');
        $this->step->save();
        log_step($this->step->id, '✓ save() completed - all changes persisted');

        info_if("[RunningToPending.handle] Step ID {$this->step->id} transitioned back to Pending (retry) (timers reset).");

        log_step($this->step->id, 'FINAL STATE AFTER TRANSITION:');
        log_step($this->step->id, '  - State: Pending');
        log_step($this->step->id, '  - Retries: '.$this->step->retries);
        log_step($this->step->id, '  - Timers: RESET');
        log_step($this->step->id, '✓ RunningToPending::handle() completed successfully');
        log_step($this->step->id, '╚═══════════════════════════════════════════════════════════╝');

        /*
        $this->step->logApplicationEvent(
            'Step transitioned back to Pending (retry) (timers reset).',
            self::class,
            __FUNCTION__
        );
        */

        return $this->step;
    }
}

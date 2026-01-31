<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Running;
use Spatie\ModelStates\Transition;

final class DispatchedToRunning extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        log_step($this->step->id, '→→→ DispatchedToRunning::canTransition() called');
        log_step($this->step->id, 'Always returns TRUE (no restrictions)');
        return true;
    }

    public function handle(): Step
    {
        log_step($this->step->id, '╔═══════════════════════════════════════════════════════════╗');
        log_step($this->step->id, '║   DispatchedToRunning::handle() - JOB START             ║');
        log_step($this->step->id, '╚═══════════════════════════════════════════════════════════╝');
        log_step($this->step->id, '→→→ JOB EXECUTION STARTING ←←←');

        log_step($this->step->id, 'BEFORE TRANSITION:');
        log_step($this->step->id, '  - Current state: '.$this->step->state);
        log_step($this->step->id, '  - Hostname: '.($this->step->hostname ?? 'null'));
        log_step($this->step->id, '  - started_at: '.($this->step->started_at ?? 'null'));
        log_step($this->step->id, '  - is_throttled: '.($this->step->is_throttled ? 'true' : 'false'));
        log_step($this->step->id, '  - retries: '.$this->step->retries);

        log_step($this->step->id, 'SETTING ATTRIBUTES:');
        log_step($this->step->id, '  - Setting hostname to: '.gethostname());
        $this->step->hostname = gethostname();

        $startedAt = now();
        log_step($this->step->id, '  - Setting started_at to: '.$startedAt->format('Y-m-d H:i:s.u'));
        $this->step->started_at = $startedAt;

        log_step($this->step->id, '  - Setting is_throttled to: FALSE (no longer throttled)');
        $this->step->is_throttled = false; // Step is no longer waiting due to throttling

        log_step($this->step->id, 'CHANGING STATE:');
        log_step($this->step->id, '  - Creating new Running state object...');
        $this->step->state = new Running($this->step);
        log_step($this->step->id, '  - New state: '.$this->step->state);

        log_step($this->step->id, 'Calling save() to persist changes...');
        $this->step->save();
        log_step($this->step->id, '✓ save() completed - step now RUNNING');

        /*
        $this->step->logApplicationEvent(
            'Step successfully transitioned to Running',
            self::class,
            __FUNCTION__
        );
        */

        log_step($this->step->id, 'FINAL STATE AFTER TRANSITION:');
        log_step($this->step->id, '  - State: Running');
        log_step($this->step->id, '  - Hostname: '.$this->step->hostname);
        log_step($this->step->id, '  - started_at: '.$this->step->started_at->format('Y-m-d H:i:s.u'));
        log_step($this->step->id, '  - is_throttled: false');
        log_step($this->step->id, '✓ DispatchedToRunning::handle() completed - job handle() will execute next');
        log_step($this->step->id, '╚═══════════════════════════════════════════════════════════╝');

        return $this->step;
    }
}

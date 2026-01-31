<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Running;
use Spatie\ModelStates\Transition;

final class PendingToRunning extends Transition
{
    public function __construct(
        private Step $step
    ) {}

    public function handle(): Step
    {
        log_step($this->step->id, '╔═══════════════════════════════════════════════════════════╗');
        log_step($this->step->id, '║   PendingToRunning::handle() - DIRECT START              ║');
        log_step($this->step->id, '╚═══════════════════════════════════════════════════════════╝');
        log_step($this->step->id, '⚠️ NOTE: This transition bypasses Dispatched state');
        log_step($this->step->id, '→→→ Setting same fields as DispatchedToRunning for consistency ←←←');

        log_step($this->step->id, 'BEFORE TRANSITION:');
        log_step($this->step->id, '  - Current state: ' . $this->step->state);
        log_step($this->step->id, '  - Hostname: ' . ($this->step->hostname ?? 'null'));
        log_step($this->step->id, '  - started_at: ' . ($this->step->started_at ?? 'null'));
        log_step($this->step->id, '  - is_throttled: ' . ($this->step->is_throttled ? 'true' : 'false'));

        log_step($this->step->id, 'SETTING ATTRIBUTES:');
        log_step($this->step->id, '  - Setting hostname to: ' . gethostname());
        $this->step->hostname = gethostname();

        $startedAt = now();
        log_step($this->step->id, '  - Setting started_at to: ' . $startedAt->format('Y-m-d H:i:s.u'));
        $this->step->started_at = $startedAt;

        log_step($this->step->id, '  - Setting is_throttled to: FALSE (no longer throttled)');
        $this->step->is_throttled = false;

        log_step($this->step->id, 'CHANGING STATE:');
        log_step($this->step->id, '  - Creating new Running state object...');
        $this->step->state = new Running($this->step);
        log_step($this->step->id, '  - New state: ' . $this->step->state);

        log_step($this->step->id, 'Calling save() to persist changes...');
        $this->step->save();
        log_step($this->step->id, '✓ save() completed - step now RUNNING');

        log_step($this->step->id, 'FINAL STATE AFTER TRANSITION:');
        log_step($this->step->id, '  - State: Running');
        log_step($this->step->id, '  - Hostname: ' . $this->step->hostname);
        log_step($this->step->id, '  - started_at: ' . $this->step->started_at->format('Y-m-d H:i:s.u'));
        log_step($this->step->id, '  - is_throttled: false');
        log_step($this->step->id, '✓ PendingToRunning::handle() completed');
        log_step($this->step->id, '╚═══════════════════════════════════════════════════════════╝');

        return $this->step;
    }
}

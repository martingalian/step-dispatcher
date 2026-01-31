<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use Spatie\ModelStates\Transition;

final class PendingToDispatched extends Transition
{
    private Step $step;

    private ?array $stepsCache;

    public function __construct(Step $step, ?array $stepsCache = null)
    {
        $this->step = $step;
        $this->stepsCache = $stepsCache;
    }

    public function canTransition(): bool
    {
        log_step($this->step->id, '═══════════════════════════════════════════════════════════');
        log_step($this->step->id, '→→→ PendingToDispatched::canTransition() START ←←←');
        log_step($this->step->id, '═══════════════════════════════════════════════════════════');
        log_step($this->step->id, 'Step state: '.$this->step->state);
        log_step($this->step->id, 'Step type: '.$this->step->type);
        log_step($this->step->id, 'Step index: '.($this->step->index ?? 'null'));
        log_step($this->step->id, 'Block UUID: '.$this->step->block_uuid);

        log_step($this->step->id, 'Checking if state is Pending...');
        if (! $this->step->state instanceof Pending) {
            log_step($this->step->id, '✗ State is NOT Pending - returning false');
            log_step($this->step->id, '═══════════════════════════════════════════════════════════');

            return false;
        }
        log_step($this->step->id, '✓ State is Pending');

        /**
         * Check if the step is a 'resolve-exception' without index.
         * The logic to put this resolve-exception into pending state is made
         * as a passive decision, no worries.
         */
        log_step($this->step->id, '[CHECK 1/4] Is resolve-exception WITHOUT index?');
        if ($this->step->type === 'resolve-exception' && is_null($this->step->index)) {
            log_step($this->step->id, '✓ YES - resolve-exception with NO index - returning TRUE');
            log_step($this->step->id, '═══════════════════════════════════════════════════════════');

            return true;
        }
        log_step($this->step->id, '✗ NO - not resolve-exception without index');

        // Check if the step is a 'resolve-exception' with an index.
        log_step($this->step->id, '[CHECK 2/4] Is resolve-exception WITH index?');
        if ($this->step->type === 'resolve-exception' && ! is_null($this->step->index)) {
            log_step($this->step->id, '✓ YES - resolve-exception with index '.$this->step->index);

            // If the index is 1, there's no previous step to check, so allow the transition
            log_step($this->step->id, 'Checking if index === 1...');
            if ($this->step->index === 1) {
                log_step($this->step->id, '✓ Index is 1 (first step) - returning TRUE');
                log_step($this->step->id, '═══════════════════════════════════════════════════════════');

                return true;
            }
            log_step($this->step->id, '✗ Index is NOT 1 - need to check previous step');

            // Ensure that the previous step (index - 1) is 'resolve-exception' and completed.
            log_step($this->step->id, 'Looking for previous resolve-exception step at index '.($this->step->index - 1));
            $previousSteps = Step::where('block_uuid', $this->step->block_uuid)
                ->where('index', $this->step->index - 1)
                ->where('type', 'resolve-exception')
                ->get();

            log_step($this->step->id, 'Found '.$previousSteps->count().' previous resolve-exception step(s)');

            // If the previous step exists and is completed, allow transition
            if ($previousSteps->isNotEmpty() && in_array(get_class($previousSteps->first()->state), Step::concludedStepStates(), strict: true)) {
                log_step($this->step->id, '✓ Previous resolve-exception step is concluded - returning TRUE');
                log_step($this->step->id, '═══════════════════════════════════════════════════════════');

                return true;
            }

            log_step($this->step->id, '✗ Previous resolve-exception step NOT concluded - returning FALSE');
            log_step($this->step->id, '═══════════════════════════════════════════════════════════');

            return false;
        }
        log_step($this->step->id, '✗ NO - not resolve-exception with index');

        /**
         * Orphan step:
         * ----------------------------
         * No parent and no child block.
         * If index is null → dispatch immediately.
         * Else → dispatch only if previous index is concluded.
         */
        log_step($this->step->id, '[CHECK 3/4] Is ORPHAN step?');
        if ($this->isOrphan()) {
            log_step($this->step->id, '✓ YES - step is ORPHAN (no parent, no children)');
            log_step($this->step->id, 'Checking index...');
            if (is_null($this->step->index)) {
                log_step($this->step->id, '✓ Index is NULL - dispatch immediately - returning TRUE');
                log_step($this->step->id, '═══════════════════════════════════════════════════════════');

                return true;
            }
            log_step($this->step->id, 'Index is '.$this->step->index.' - checking if previous index is concluded');
            $result = $this->previousIndexIsConcluded();
            log_step($this->step->id, 'previousIndexIsConcluded() returned: '.($result ? 'TRUE' : 'FALSE'));
            log_step($this->step->id, '═══════════════════════════════════════════════════════════');

            return $result;
        }
        log_step($this->step->id, '✗ NO - not orphan');

        /**
         * Child step:
         * ----------------------------
         * Belongs to a child block (has a parent).
         * Dispatch if parent has started (Running or Completed)
         * and previous index in same block is concluded.
         */
        log_step($this->step->id, '[CHECK 4/4] Is CHILD step?');
        if ($this->isChild()) {
            log_step($this->step->id, '✓ YES - step is CHILD (has parent)');
            log_step($this->step->id, 'Getting parent step...');
            $parent = $this->getParentStep();

            if (! $parent) {
                log_step($this->step->id, '✗ Parent step NOT found - returning FALSE');
                log_step($this->step->id, '═══════════════════════════════════════════════════════════');

                return false;
            }
            log_step($this->step->id, '✓ Parent found: Step #'.$parent->id.' | state: '.$parent->state);

            $parentState = get_class($parent->state);
            log_step($this->step->id, 'Checking if parent is Running or Completed...');
            if (! in_array($parentState, [Running::class, Completed::class], strict: true)) {
                log_step($this->step->id, '✗ Parent is NOT Running/Completed - returning FALSE');
                log_step($this->step->id, '═══════════════════════════════════════════════════════════');

                return false;
            }
            log_step($this->step->id, '✓ Parent is Running or Completed');

            log_step($this->step->id, 'Checking if previous index is concluded...');
            $result = $this->previousIndexIsConcluded();
            log_step($this->step->id, 'previousIndexIsConcluded() returned: '.($result ? 'TRUE' : 'FALSE'));
            log_step($this->step->id, '═══════════════════════════════════════════════════════════');

            return $result;
        }
        log_step($this->step->id, '✗ NO - not child');

        /**
         * Parent step:
         * ----------------------------
         * Spawns a child block (has child_block_uuid).
         * Dispatch if previous index is concluded.
         * Children may not exist yet at this point.
         */
        log_step($this->step->id, '[CHECK 5/5] Is PARENT step?');
        if ($this->isParent()) {
            log_step($this->step->id, '✓ YES - step is PARENT (has child_block_uuid: '.$this->step->child_block_uuid.')');
            log_step($this->step->id, 'Checking if previous index is concluded...');
            $result = $this->previousIndexIsConcluded();
            log_step($this->step->id, 'previousIndexIsConcluded() returned: '.($result ? 'TRUE' : 'FALSE'));
            log_step($this->step->id, '═══════════════════════════════════════════════════════════');

            return $result;
        }
        log_step($this->step->id, '✗ NO - not parent');

        /**
         * Fallback:
         * ----------------------------
         * Not orphan, not child, not parent.
         * Should never happen, deny dispatch.
         */
        log_step($this->step->id, '⚠️ FALLBACK: Step is neither orphan, child, nor parent - returning FALSE');
        log_step($this->step->id, 'This should never happen - investigate!');
        log_step($this->step->id, '═══════════════════════════════════════════════════════════');

        return false;
    }

    public function handle(): Step
    {
        return $this->apply();
    }

    public function apply(): Step
    {
        log_step($this->step->id, '╔═══════════════════════════════════════════════════════════╗');
        log_step($this->step->id, '║      PendingToDispatched::apply() - STATE CHANGE         ║');
        log_step($this->step->id, '╚═══════════════════════════════════════════════════════════╝');
        log_step($this->step->id, 'BEFORE: state = '.$this->step->state);

        log_step($this->step->id, 'Creating new Dispatched state object...');
        $this->step->state = new Dispatched($this->step); // Transition to Dispatched state
        log_step($this->step->id, 'AFTER: state = '.$this->step->state);

        // If we have a tick id, let's update the step with it.
        // Cache key includes group suffix to match StepsDispatcher::startDispatch()
        $cacheSuffix = $this->step->group ?? 'global';
        $cacheKey = "current_tick_id:{$cacheSuffix}";

        if (cache()->has($cacheKey)) {
            $tickId = cache($cacheKey);
            log_step($this->step->id, "Setting tick_id from cache ({$cacheKey}): {$tickId}");
            $this->step->tick_id = $tickId;
        } else {
            log_step($this->step->id, "No tick_id in cache ({$cacheKey}) - not setting tick_id");
        }

        log_step($this->step->id, 'Calling save()...');
        $this->step->save();
        log_step($this->step->id, 'save() completed - state transition persisted');
        log_step($this->step->id, '✓ PendingToDispatched::apply() completed successfully');
        log_step($this->step->id, '╚═══════════════════════════════════════════════════════════╝');

        return $this->step;
    }

    /**
     * Get parent step from cache or database.
     * Replicates Step::parentStep() logic but uses cache when available.
     */
    private function getParentStep(): ?Step
    {
        if ($this->stepsCache !== null) {
            return $this->stepsCache['parents_by_child_block'][$this->step->block_uuid] ?? null;
        }

        return Step::where('child_block_uuid', $this->step->block_uuid)->first();
    }

    /**
     * Check if previous index is concluded from cache or database.
     * Replicates Step::previousIndexIsConcluded() logic but uses cache when available.
     */
    private function previousIndexIsConcluded(): bool
    {
        if ($this->step->index === 1) {
            return true;
        }

        if ($this->step->index === null && $this->isChild() && $this->parentIsRunning()) {
            return true;
        }

        if ($this->stepsCache !== null) {
            return $this->previousIndexIsConcludedFromCache();
        }

        return $this->previousIndexIsConcludedFromDatabase();
    }

    /**
     * Check if previous index is concluded using the cache.
     */
    private function previousIndexIsConcludedFromCache(): bool
    {
        $hasPendingResolveException = isset($this->stepsCache['pending_resolve_exceptions'][$this->step->block_uuid]);

        $key = $this->step->block_uuid.'_'.($this->step->index - 1);
        $previousSteps = $this->stepsCache['steps_by_block_and_index'][$key] ?? collect([]);

        if ($hasPendingResolveException) {
            $previousSteps = $previousSteps->where('type', 'resolve-exception');
        } else {
            $previousSteps = $previousSteps->where('type', 'default');
        }

        if ($previousSteps->isEmpty()) {
            return false;
        }

        return $previousSteps->every(
            static fn ($step) => in_array(get_class($step->state), Step::concludedStepStates(), strict: true)
        );
    }

    /**
     * Check if previous index is concluded using database queries (fallback).
     */
    private function previousIndexIsConcludedFromDatabase(): bool
    {
        $hasPendingResolveException = Step::where('block_uuid', $this->step->block_uuid)
            ->where('type', 'resolve-exception')
            ->where('state', Pending::class)
            ->exists();

        $query = Step::where('block_uuid', $this->step->block_uuid)
            ->where('index', $this->step->index - 1);

        if ($hasPendingResolveException) {
            $query->where('type', 'resolve-exception');
        } else {
            $query->where('type', 'default');
        }

        $previousSteps = $query->get();

        if ($previousSteps->isEmpty()) {
            return false;
        }

        return $previousSteps->every(
            static fn ($step) => in_array(get_class($step->state), Step::concludedStepStates(), strict: true)
        );
    }

    /**
     * Check if parent is running (helper for previousIndexIsConcluded).
     */
    private function parentIsRunning(): bool
    {
        $parent = $this->getParentStep();

        return $parent && $parent->state->equals(Running::class);
    }

    /**
     * Check if step is a child (has a parent) using cache when available.
     * Replicates Step::isChild() logic.
     */
    private function isChild(): bool
    {
        if ($this->stepsCache !== null) {
            return isset($this->stepsCache['parents_by_child_block'][$this->step->block_uuid]);
        }

        return Step::where('child_block_uuid', $this->step->block_uuid)->exists();
    }

    /**
     * Check if step is a parent (has children) using cache when available.
     * Replicates Step::isParent() logic.
     */
    private function isParent(): bool
    {
        return ! is_null($this->step->child_block_uuid);
    }

    /**
     * Check if step is an orphan (no parent, no children) using cache when available.
     * Replicates Step::isOrphan() logic.
     */
    private function isOrphan(): bool
    {
        return is_null($this->step->child_block_uuid) && is_null($this->getParentStep());
    }
}

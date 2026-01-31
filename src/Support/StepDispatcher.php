<?php

declare(strict_types=1);

namespace StepDispatcher\Support;

use Illuminate\Support\Facades\DB;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Transitions\PendingToDispatched;

final class StepDispatcher
{
    use DispatchesJobs;

    /**
     * Run a single "tick" of the dispatcher, optionally constrained to a group.
     *
     * @param  string|null  $group  If provided, ALL Step selections are filtered by this group.
     */
    public static function dispatch(?string $group = null): void
    {
        log_step('dispatcher', '╔══════════════════════════════════════════════════════════════╗');
        log_step('dispatcher', '║              DISPATCHER TICK START                           ║');
        log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
        log_step('dispatcher', 'Group: '.($group ?? 'null (all groups)'));

        // Acquire the DB lock authoritatively; bail if already running.
        if (! StepsDispatcher::startDispatch($group)) {
            log_step('dispatcher', '⚠️ [TICK SKIPPED] Already running');

            return;
        }

        $progress = 0;
        log_step('dispatcher', 'Progress: 0 - Lock acquired, starting dispatcher cycle');

        try {
            // Marks as skipped all children steps on a skipped step.
            log_step('dispatcher', '→ Calling skipAllChildStepsOnParentAndChildSingleStep()');
            $result = self::skipAllChildStepsOnParentAndChildSingleStep($group);
            log_step('dispatcher', '← skipAllChildStepsOnParentAndChildSingleStep() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (skipAllChildStepsOnParentAndChildSingleStep = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 0 (skip children)');

                return;
            }

            $progress = 1;
            log_step('dispatcher', 'Progress: 1 - Skip children check complete');

            // Perform cascading cancellation for failed steps and return early if needed
            log_step('dispatcher', '→ Calling cascadeCancelledSteps()');
            $result = self::cascadeCancelledSteps($group);
            log_step('dispatcher', '← cascadeCancelledSteps() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (cascadeCancelledSteps = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 1 (cascade cancelled)');

                return;
            }

            $progress = 2;
            log_step('dispatcher', 'Progress: 2 - Cascade cancelled check complete');

            log_step('dispatcher', '→ Calling promoteResolveExceptionSteps()');
            $result = self::promoteResolveExceptionSteps($group);
            log_step('dispatcher', '← promoteResolveExceptionSteps() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (promoteResolveExceptionSteps = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 2 (promote resolve-exception)');

                return;
            }

            $progress = 3;
            log_step('dispatcher', 'Progress: 3 - Promote resolve-exception check complete');

            // Check if we need to transition parent steps to Failed first, but only if no cancellations occurred
            log_step('dispatcher', '→ Calling transitionParentsToFailed()');
            $result = self::transitionParentsToFailed($group);
            log_step('dispatcher', '← transitionParentsToFailed() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (transitionParentsToFailed = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 3 (transition parents to failed)');

                return;
            }

            $progress = 4;
            log_step('dispatcher', 'Progress: 4 - Transition parents to failed check complete');

            // Check if we need to transition parent steps to Stopped (child was stopped)
            log_step('dispatcher', '→ Calling transitionParentsToStopped()');
            $result = self::transitionParentsToStopped($group);
            log_step('dispatcher', '← transitionParentsToStopped() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (transitionParentsToStopped = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 4 (transition parents to stopped)');

                return;
            }

            $progress = 5;
            log_step('dispatcher', 'Progress: 5 - Transition parents to stopped check complete');

            log_step('dispatcher', '→ Calling cascadeCancellationToChildren()');
            $result = self::cascadeCancellationToChildren($group);
            log_step('dispatcher', '← cascadeCancellationToChildren() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (cascadeCancellationToChildren = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 5 (cascade cancellation to children)');

                return;
            }

            $progress = 6;
            log_step('dispatcher', 'Progress: 6 - Cascade cancellation to children check complete');

            // Check if we need to transition parent steps to Completed
            log_step('dispatcher', '→ Calling transitionParentsToComplete()');
            $result = self::transitionParentsToComplete($group);
            log_step('dispatcher', '← transitionParentsToComplete() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (transitionParentsToComplete = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 6 (transition parents to complete)');

                return;
            }

            $progress = 7;
            log_step('dispatcher', 'Progress: 7 - Transition parents to complete check complete');

            // Distribute the steps to be dispatched (only if no cancellations or failures happened)
            log_step('dispatcher', '→ Starting pending step evaluation and dispatch');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            log_step('dispatcher', '→→→ PENDING STEP SELECTION DIAGNOSTICS ←←←');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            $dispatchedSteps = collect();

            $pendingQuery = Step::pending()
                ->when($group !== null, static fn ($q) => $q->where('group', $group), static fn ($q) => $q->whereNull('group'))
                ->where(static function ($q) {
                    $q->whereNull('dispatch_after')
                        ->orWhere('dispatch_after', '<=', now());
                });

            log_step('dispatcher', '🔍 DIAGNOSTIC: Step::pending() scope ONLY selects state = Pending::class');
            log_step('dispatcher', '🔍 DIAGNOSTIC: Query filters: group = '.($group ?? 'NULL').', dispatch_after <= '.now());

            $pendingSteps = $pendingQuery->get();
            log_step('dispatcher', 'Found '.$pendingSteps->count().' PENDING steps ready for evaluation');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');

            // Priority Queue System: If any high-priority steps exist, filter to only those
            if ($pendingSteps->contains('priority', 'high')) {
                $totalSteps = $pendingSteps->count();
                $pendingSteps = $pendingSteps->where('priority', 'high')->values();
                info_if('[StepDispatcher] High-priority steps detected - filtering to '.$pendingSteps->count().' high-priority steps (from '.$totalSteps.' total pending steps)');
                log_step('dispatcher', '⬆️ HIGH-PRIORITY FILTERING: '.$pendingSteps->count().' high-priority steps (from '.$totalSteps.' total)');
            }

            // Build cache to avoid N+1 queries in PendingToDispatched::canTransition()
            $stepsCache = null;
            $blockUuids = $pendingSteps->pluck('block_uuid')->unique()->values();

            if ($blockUuids->isNotEmpty()) {
                // Query 1: Get parent steps (for isChild/getParentStep checks)
                $parentsByChildBlock = Step::whereIn('child_block_uuid', $blockUuids)
                    ->get()
                    ->keyBy('child_block_uuid');

                // Query 2: Get all steps in these blocks (for previousIndexIsConcluded)
                $stepsByBlockAndIndex = Step::whereIn('block_uuid', $blockUuids)
                    ->get()
                    ->groupBy(static fn (Step $s): string => $s->block_uuid.'_'.$s->index);

                // Query 3: Get blocks with pending resolve-exceptions
                $pendingResolveExceptions = Step::whereIn('block_uuid', $blockUuids)
                    ->where('type', 'resolve-exception')
                    ->where('state', Pending::class)
                    ->pluck('block_uuid')
                    ->flip()
                    ->all();

                $stepsCache = [
                    'parents_by_child_block' => $parentsByChildBlock,
                    'steps_by_block_and_index' => $stepsByBlockAndIndex,
                    'pending_resolve_exceptions' => $pendingResolveExceptions,
                ];
            }

            // Batch pre-compute which steps can be dispatched (eliminates per-step canTransition() calls)
            $dispatchableSteps = self::computeDispatchableSteps($pendingSteps, $stepsCache);
            $canTransitionCount = $dispatchableSteps->count();
            $cannotTransitionCount = $pendingSteps->count() - $canTransitionCount;

            log_step('dispatcher', 'Batch decision complete:');
            log_step('dispatcher', '  - Can transition: '.$canTransitionCount);
            log_step('dispatcher', '  - Cannot transition: '.$cannotTransitionCount);
            log_step('dispatcher', '  - Total evaluated: '.$pendingSteps->count());

            // Apply transitions for all dispatchable steps
            $dispatchableSteps->each(static function (Step $step) use ($dispatchedSteps, $stepsCache) {
                info_if("[StepDispatcher.dispatch] Step ID {$step->id} CAN transition to DISPATCHED");
                log_step($step->id, '→ Applying PendingToDispatched transition');

                $transition = new PendingToDispatched($step, $stepsCache);
                $transition->apply();
                $dispatchedSteps->push($step);

                log_step($step->id, '✓ Step transitioned to DISPATCHED');
            });

            // Dispatch all steps that are ready
            log_step('dispatcher', 'Dispatching '.$dispatchedSteps->count().' steps to their jobs...');
            $dispatchedSteps->each(static function ($step) {
                log_step($step->id, 'DISPATCHER: Calling dispatchSingleStep() to dispatch job to queue');
                (new self)->dispatchSingleStep($step);
                log_step($step->id, 'DISPATCHER: dispatchSingleStep() completed - job queued');
            });

            info_if('Total steps dispatched: '.$dispatchedSteps->count().($group ? " [group={$group}]" : ''));
            info_if('-= TICK ENDED (full cycle) =-');
            log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
            log_step('dispatcher', '  TICK ENDED NORMALLY at progress 7 (full cycle complete)');
            log_step('dispatcher', '  Total steps dispatched: '.$dispatchedSteps->count());

            $progress = 8;
        } finally {
            StepsDispatcher::endDispatch($progress, $group);
        }
    }

    /**
     * Transition running parents to Completed if all their (nested) children concluded.
     */
    public static function transitionParentsToComplete(?string $group = null): bool
    {
        $runningParents = Step::where('state', Running::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->orderBy('index')
            ->orderBy('id')
            ->get();

        log_step('dispatcher', '[StepDispatcher.transitionParentsToComplete] Found '.$runningParents->count().' running parents');

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = self::collectAllNestedChildBlocks($runningParents, $group);
        log_step('dispatcher', '[StepDispatcher.transitionParentsToComplete] Collected '.count($childBlockUuids).' nested child block UUIDs');

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->get()
            ->groupBy('block_uuid');

        log_step('dispatcher', '[StepDispatcher.transitionParentsToComplete] Loaded child steps for '.$childStepsByBlock->count().' blocks');

        $changed = false;

        foreach ($runningParents as $step) {
            log_step($step->id, "[StepDispatcher.transitionParentsToComplete] Checking Parent Step #{$step->id} | CLASS: {$step->class} | child_block_uuid: {$step->child_block_uuid}");

            try {
                $areConcluded = $step->childStepsAreConcludedFromMap($childStepsByBlock);
                log_step($step->id, "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | childStepsAreConcludedFromMap returned: ".($areConcluded ? 'TRUE' : 'FALSE'));

                if ($areConcluded) {
                    log_step($step->id, "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | DECISION: TRANSITION TO COMPLETED");
                    $step->state->transitionTo(Completed::class);
                    $changed = true;
                    log_step($step->id, "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | SUCCESS - Transitioned to Completed");
                } else {
                    log_step($step->id, "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | DECISION: SKIP - Children not concluded yet");
                }
            } catch (\Exception $e) {
                log_step($step->id, "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | EXCEPTION during transition: ".$e->getMessage().' | File: '.$e->getFile().':'.$e->getLine());
            }
        }

        return $changed;
    }

    /**
     * If a parent was skipped, mark all its descendants as skipped.
     */
    public static function skipAllChildStepsOnParentAndChildSingleStep(?string $group = null): bool
    {
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ skipAllChildStepsOnParentAndChildSingleStep START ←←←');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        $skippedParents = Step::where('state', Skipped::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        log_step('dispatcher', 'Found '.$skippedParents->count().' skipped parent steps with children');

        if ($skippedParents->isEmpty()) {
            log_step('dispatcher', 'No skipped parents found - returning false');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');

            return false;
        }

        foreach ($skippedParents as $parent) {
            log_step($parent->id, 'SKIPPED PARENT: Step #'.$parent->id.' | child_block_uuid: '.$parent->child_block_uuid);
        }

        $allChildBlocks = self::collectAllNestedChildBlocks($skippedParents, $group);
        log_step('dispatcher', 'Collected '.count($allChildBlocks).' nested child block UUIDs');

        if (empty($allChildBlocks)) {
            log_step('dispatcher', 'No child blocks found - returning true (early completion)');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');

            return true;
        }

        $descendantIds = Step::whereIn('block_uuid', $allChildBlocks)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->pluck('id')
            ->all();

        log_step('dispatcher', 'Found '.count($descendantIds).' descendant steps to skip');

        if (! empty($descendantIds)) {
            info_if('[StepDispatcher.skipAllChildStepsOnParentAndChildSingleStep] - Batch transitioning '.count($descendantIds).' steps to SKIPPED');
            log_step('dispatcher', 'Calling batchTransitionSteps() to skip '.count($descendantIds).' steps');

            foreach ($descendantIds as $stepId) {
                log_step($stepId, '⚠️ BATCH SKIPPED: Step #'.$stepId.' is being skipped (parent was skipped)');
            }

            self::batchTransitionSteps($descendantIds, Skipped::class);
            log_step('dispatcher', 'batchTransitionSteps() completed - all descendants now SKIPPED');
        }

        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ skipAllChildStepsOnParentAndChildSingleStep END ←←←');
        log_step('dispatcher', 'Returning true (children were skipped)');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        return true;
    }

    /**
     * Transition running parents to Failed if any child in their block failed.
     * Note: Only handles Failed children. Stopped children are handled by transitionParentsToStopped().
     */
    public static function transitionParentsToFailed(?string $group = null): bool
    {
        $runningParents = Step::where('state', Running::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = $runningParents->pluck('child_block_uuid')->filter()->unique()->all();

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->get()
            ->groupBy('block_uuid');

        foreach ($runningParents as $parentStep) {
            $childSteps = $childStepsByBlock->get($parentStep->child_block_uuid, collect());

            log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Checking Parent Step #{$parentStep->id} | CLASS: {$parentStep->class} | child_block_uuid: {$parentStep->child_block_uuid} | Children count: ".$childSteps->count());

            if ($childSteps->isEmpty()) {
                log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | WARNING: No children found for child_block_uuid");

                continue;
            }

            $allChildStates = $childSteps->map(static fn ($s) => "ID:{$s->id}=".class_basename($s->state))->join(', ');
            log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | All child states: [{$allChildStates}]");

            // Only check for Failed children (not Stopped - that's handled by transitionParentsToStopped)
            $failedChildSteps = $childSteps->filter(
                static fn ($step) => get_class($step->state) === Failed::class
            );

            if ($failedChildSteps->isNotEmpty()) {
                $failedIds = $failedChildSteps->pluck('id')->join(', ');
                log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | Failed children detected: [{$failedIds}]");

                // Check if there are any non-terminal resolve-exception steps in the child block.
                // If so, wait for them to complete before failing the parent.
                $nonTerminalResolveExceptions = $childSteps->filter(
                    static function ($step) {
                        return $step->type === 'resolve-exception'
                            && ! in_array(get_class($step->state), Step::terminalStepStates(), strict: true);
                    }
                );

                if ($nonTerminalResolveExceptions->isNotEmpty()) {
                    $resolveIds = $nonTerminalResolveExceptions->pluck('id')->join(', ');
                    $resolveStates = $nonTerminalResolveExceptions->map(static fn ($s) => 'ID:'.$s->id.'='.class_basename($s->state))->join(', ');
                    log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | DECISION: WAIT - Child block has non-terminal resolve-exception steps: [{$resolveStates}]");
                    log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | Waiting for resolve-exceptions [{$resolveIds}] to complete before failing parent");

                    continue;
                }

                // Get the indices of all failed children to check parallel siblings
                $failedIndices = $failedChildSteps->pluck('index')->unique();

                // Check if there are any non-terminal siblings at the same index as the failed children.
                // When steps run in parallel (same index), we must wait for ALL to reach terminal state.
                $nonTerminalParallelSiblings = $childSteps->filter(
                    static function ($step) use ($failedIndices) {
                        // Only check steps at the same index as failed steps
                        if (! $failedIndices->contains($step->index)) {
                            return false;
                        }

                        // Skip steps that are already in terminal state
                        return ! in_array(get_class($step->state), Step::terminalStepStates(), strict: true);
                    }
                );

                if ($nonTerminalParallelSiblings->isNotEmpty()) {
                    $siblingIds = $nonTerminalParallelSiblings->pluck('id')->join(', ');
                    $siblingStates = $nonTerminalParallelSiblings->map(static fn ($s) => 'ID:'.$s->id.'='.class_basename($s->state))->join(', ');
                    log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | DECISION: WAIT - Parallel siblings still running at index [{$failedIndices->join(',')}]: [{$siblingStates}]");

                    continue;
                }

                log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | DECISION: TRANSITION TO FAILED | No pending resolve-exceptions or parallel siblings");

                // Set error message before transitioning to Failed
                $parentStep->update([
                    'error_message' => "Child step(s) failed: [{$failedIds}]",
                ]);

                $parentStep->state->transitionTo(Failed::class);

                return true;
            }

            log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | DECISION: SKIP - No failed children");
        }

        return false;
    }

    /**
     * Transition running parents to Stopped if any child in their block was stopped.
     */
    public static function transitionParentsToStopped(?string $group = null): bool
    {
        $runningParents = Step::where('state', Running::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = $runningParents->pluck('child_block_uuid')->filter()->unique()->all();

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->get()
            ->groupBy('block_uuid');

        foreach ($runningParents as $parentStep) {
            $childSteps = $childStepsByBlock->get($parentStep->child_block_uuid, collect());

            log_step($parentStep->id, "[StepDispatcher.transitionParentsToStopped] Checking Parent Step #{$parentStep->id} | CLASS: {$parentStep->class} | child_block_uuid: {$parentStep->child_block_uuid} | Children count: ".$childSteps->count());

            if ($childSteps->isEmpty()) {
                log_step($parentStep->id, "[StepDispatcher.transitionParentsToStopped] Parent Step #{$parentStep->id} | WARNING: No children found for child_block_uuid");

                continue;
            }

            $allChildStates = $childSteps->map(static fn ($s) => "ID:{$s->id}=".class_basename($s->state))->join(', ');
            log_step($parentStep->id, "[StepDispatcher.transitionParentsToStopped] Parent Step #{$parentStep->id} | All child states: [{$allChildStates}]");

            // Check for Stopped children
            $stoppedChildSteps = $childSteps->filter(
                static fn ($step) => get_class($step->state) === Stopped::class
            );

            if ($stoppedChildSteps->isNotEmpty()) {
                $stoppedIds = $stoppedChildSteps->pluck('id')->join(', ');
                log_step($parentStep->id, "[StepDispatcher.transitionParentsToStopped] Parent Step #{$parentStep->id} | Stopped children detected: [{$stoppedIds}]");

                log_step($parentStep->id, "[StepDispatcher.transitionParentsToStopped] Parent Step #{$parentStep->id} | DECISION: TRANSITION TO STOPPED");

                // Set error message before transitioning to Stopped
                $parentStep->update([
                    'error_message' => "Child step(s) stopped: [{$stoppedIds}]",
                ]);

                $parentStep->state->transitionTo(Stopped::class);

                return true;
            }

            log_step($parentStep->id, "[StepDispatcher.transitionParentsToStopped] Parent Step #{$parentStep->id} | DECISION: SKIP - No stopped children");
        }

        return false;
    }

    /**
     * Cancel downstream runnable steps after a failure/stop.
     */
    public static function cascadeCancelledSteps(?string $group = null): bool
    {
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ cascadeCancelledSteps START ←←←');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        $cancellationsOccurred = false;

        // Find all failed/stopped steps that should trigger downstream cancellation.
        $failedSteps = Step::whereIn('state', Step::failedStepStates())
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('index')
            ->get();

        log_step('dispatcher', 'Found '.$failedSteps->count().' failed/stopped steps that may trigger cancellations');

        if ($failedSteps->isEmpty()) {
            log_step('dispatcher', 'No failed steps found - returning false');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');

            return false;
        }

        foreach ($failedSteps as $failedStep) {
            $blockUuid = $failedStep->block_uuid;
            log_step($failedStep->id, 'CASCADE SOURCE: Step #'.$failedStep->id.' | index: '.$failedStep->index.' | block: '.$blockUuid.' | state: '.$failedStep->state);
            log_step('dispatcher', 'Checking for steps to cancel after Step #'.$failedStep->id.' (index: '.$failedStep->index.')');

            // Cancel only non-terminal, runnable steps at higher indexes in the same block.
            $stepsToCancel = Step::where('block_uuid', $blockUuid)
                ->when($group !== null, static fn ($q) => $q->where('group', $group))
                ->where('index', '>', $failedStep->index)
                ->whereNotIn('state', array_merge(Step::terminalStepStates(), [NotRunnable::class]))
                ->where('type', 'default')
                ->get();

            log_step('dispatcher', 'Found '.$stepsToCancel->count().' steps with higher index in block '.$blockUuid);

            if ($stepsToCancel->isEmpty()) {
                log_step('dispatcher', 'No steps to cancel for failed Step #'.$failedStep->id);

                continue;
            }

            info_if("[StepDispatcher.cascadeCancelledSteps] Total Steps to cancel: {$stepsToCancel->count()}");

            $stepIdsToCancel = [];
            $parentSteps = [];

            foreach ($stepsToCancel as $step) {
                log_step($step->id, 'CANCELLATION CANDIDATE: Step #'.$step->id.' | index: '.$step->index.' | state: '.$step->state.' | isParent: '.($step->isParent() ? 'yes' : 'no'));

                // Double guard: never attempt to transition terminal states.
                if (in_array(get_class($step->state), Step::terminalStepStates(), strict: true)) {
                    log_step($step->id, '⚠️ SKIP: Step #'.$step->id.' is in terminal state - will not cancel');

                    continue;
                }

                $stepIdsToCancel[] = $step->id;
                log_step($step->id, '✓ WILL CANCEL: Step #'.$step->id.' added to cancellation batch');

                // Track parent steps to cancel their children
                if ($step->isParent()) {
                    $parentSteps[] = $step;
                    log_step($step->id, '→ Parent step - child blocks will also be cancelled');
                }
            }

            if (! empty($stepIdsToCancel)) {
                info_if('[StepDispatcher.cascadeCancelledSteps] Batch cancelling '.count($stepIdsToCancel)." steps in block {$blockUuid}");
                log_step('dispatcher', 'Batch cancelling '.count($stepIdsToCancel).' steps in block '.$blockUuid);

                foreach ($stepIdsToCancel as $stepId) {
                    log_step($stepId, '⚠️ BATCH CANCELLED: Step #'.$stepId.' is being cancelled (downstream of failure)');
                }

                self::batchTransitionSteps($stepIdsToCancel, Cancelled::class);
                log_step('dispatcher', 'batchTransitionSteps() completed - steps now CANCELLED');
                $cancellationsOccurred = true;

                // Cancel child blocks
                log_step('dispatcher', 'Checking '.count($parentSteps).' parent steps for child block cancellation');
                foreach ($parentSteps as $parentStep) {
                    log_step($parentStep->id, 'Calling cancelChildBlockSteps() for parent Step #'.$parentStep->id);
                    self::cancelChildBlockSteps($parentStep, $group);
                    log_step($parentStep->id, 'cancelChildBlockSteps() completed for parent Step #'.$parentStep->id);
                }
            }
        }

        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ cascadeCancelledSteps END ←←←');
        log_step('dispatcher', 'Returning: '.($cancellationsOccurred ? 'true (cancellations occurred)' : 'false (no cancellations)'));
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        return $cancellationsOccurred;
    }

    /**
     * Cancel all pending children in a child block.
     */
    public static function cancelChildBlockSteps(Step $parentStep, ?string $group = null): void
    {
        $childBlockUuid = $parentStep->child_block_uuid;

        $childStepIds = Step::where('block_uuid', $childBlockUuid)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('state', Pending::class)
            ->pluck('id')
            ->all();

        if (! empty($childStepIds)) {
            info_if('[StepDispatcher.cancelChildBlockSteps] Batch cancelling '.count($childStepIds)." steps in child block {$childBlockUuid}");
            self::batchTransitionSteps($childStepIds, Cancelled::class);
        }
    }

    /**
     * Promote resolve-exception steps in blocks that have failures.
     */
    public static function promoteResolveExceptionSteps(?string $group = null): bool
    {
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ promoteResolveExceptionSteps START ←←←');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        $candidateBlocks = Step::where('type', 'resolve-exception')
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('state', NotRunnable::class)
            ->pluck('block_uuid')
            ->filter()
            ->unique()
            ->values();

        log_step('dispatcher', 'Found '.count($candidateBlocks).' blocks with resolve-exception steps in NotRunnable state');

        if ($candidateBlocks->isEmpty()) {
            log_step('dispatcher', 'No candidate blocks found - returning false');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');

            return false;
        }

        $failingBlocks = Step::whereIn('block_uuid', $candidateBlocks)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('type', '<>', 'resolve-exception')
            ->whereIn('state', Step::failedStepStates())
            ->pluck('block_uuid')
            ->unique()
            ->values();

        log_step('dispatcher', 'Found '.count($failingBlocks).' blocks that have failures (and resolve-exception steps)');

        if ($failingBlocks->isEmpty()) {
            log_step('dispatcher', 'No failing blocks with resolve-exception steps - returning false');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');

            return false;
        }

        $block = $failingBlocks->first();
        log_step('dispatcher', 'Promoting resolve-exception steps in first failing block: '.$block);

        $stepIds = Step::where('block_uuid', $block)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('type', 'resolve-exception')
            ->where('state', NotRunnable::class)
            ->pluck('id')
            ->all();

        log_step('dispatcher', 'Found '.count($stepIds).' resolve-exception steps to promote in block '.$block);

        if (! empty($stepIds)) {
            info_if('[StepDispatcher.promoteResolveExceptionSteps] Batch promoting '.count($stepIds)." resolve-exception steps in block {$block}");

            foreach ($stepIds as $stepId) {
                log_step($stepId, '⬆️ PROMOTED: Step #'.$stepId.' (resolve-exception) is being promoted to Pending (failure detected in block)');
            }

            self::batchTransitionSteps($stepIds, Pending::class);
            log_step('dispatcher', 'batchTransitionSteps() completed - resolve-exception steps now PENDING');
        }

        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ promoteResolveExceptionSteps END ←←←');
        log_step('dispatcher', 'Returning true (resolve-exception steps promoted)');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        return true;
    }

    /**
     * If a parent failed/stopped/cancelled, cancel all its non-terminal children.
     * Children that never ran should be Cancelled, not Failed.
     * Also handles recursive cancellation when a cancelled parent has children.
     */
    public static function cascadeCancellationToChildren(?string $group = null): bool
    {
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ cascadeCancellationToChildren START ←←←');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        // Also include Cancelled - a cancelled parent must also cancel its children recursively
        $failedOrStoppedParents = Step::whereIn('state', [Failed::class, Stopped::class, Cancelled::class])
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        log_step('dispatcher', 'Found '.$failedOrStoppedParents->count().' failed/stopped/cancelled parent steps with children');

        if ($failedOrStoppedParents->isEmpty()) {
            log_step('dispatcher', 'No failed/stopped/cancelled parents found - returning false');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');

            return false;
        }

        foreach ($failedOrStoppedParents as $parent) {
            $childBlock = $parent->child_block_uuid;
            log_step($parent->id, 'FAILED/STOPPED/CANCELLED PARENT: Step #'.$parent->id.' | state: '.$parent->state.' | child_block_uuid: '.$childBlock);
            log_step('dispatcher', 'Checking child block '.$childBlock.' for parent Step #'.$parent->id);

            $nonTerminalChildIds = Step::where('block_uuid', $childBlock)
                ->when($group !== null, static fn ($q) => $q->where('group', $group))
                ->whereNotIn('state', Step::terminalStepStates())
                ->pluck('id')
                ->all();

            log_step('dispatcher', 'Found '.count($nonTerminalChildIds).' non-terminal children in block '.$childBlock);

            if (empty($nonTerminalChildIds)) {
                log_step('dispatcher', 'No non-terminal children to cancel for parent Step #'.$parent->id);

                continue;
            }

            info_if('[StepDispatcher.cascadeCancellationToChildren] Batch cancelling '.count($nonTerminalChildIds)." children in block {$childBlock}");
            log_step('dispatcher', 'Batch cancelling '.count($nonTerminalChildIds).' children in block '.$childBlock);

            foreach ($nonTerminalChildIds as $childId) {
                log_step($childId, '⚠️ BATCH CANCELLED: Step #'.$childId.' is being cancelled (parent failed/stopped/cancelled)');
            }

            self::batchTransitionSteps($nonTerminalChildIds, Cancelled::class);
            log_step('dispatcher', 'batchTransitionSteps() completed - children now CANCELLED');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            log_step('dispatcher', '→→→ cascadeCancellationToChildren END ←←←');
            log_step('dispatcher', 'Returning true (ended tick after cancelling one child block)');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');

            return true; // end tick after cancelling one block
        }

        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ cascadeCancellationToChildren END ←←←');
        log_step('dispatcher', 'Returning false (no children to cancel)');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        return false;
    }

    /**
     * Collect all nested child block UUIDs reachable from the given parent steps.
     * Optimized with recursive CTE query for better performance.
     */
    public static function collectAllNestedChildBlocks($parents, ?string $group = null): array
    {
        $rootBlocks = $parents->pluck('child_block_uuid')->filter()->unique()->values();

        if ($rootBlocks->isEmpty()) {
            return [];
        }

        $placeholders = implode(separator: ',', array: array_fill(0, $rootBlocks->count(), '?'));

        $sql = "
            WITH RECURSIVE descendants AS (
                SELECT child_block_uuid AS block_uuid
                FROM steps
                WHERE child_block_uuid IN ({$placeholders})
                  AND child_block_uuid IS NOT NULL
                  ".($group !== null ? 'AND `group` = ?' : '').'

                UNION ALL

                SELECT s.child_block_uuid
                FROM steps s
                INNER JOIN descendants d ON s.block_uuid = d.block_uuid
                WHERE s.child_block_uuid IS NOT NULL
                  '.($group !== null ? 'AND s.`group` = ?' : '').'
            )
            SELECT DISTINCT block_uuid FROM descendants
        ';

        $bindings = $rootBlocks->values()->all();
        if ($group !== null) {
            $bindings[] = $group;
            $bindings[] = $group;
        }

        $results = DB::select($sql, $bindings);

        return collect($results)->pluck('block_uuid')->unique()->values()->all();
    }

    /**
     * Batch transition steps to a new state using proper state machine transitions.
     *
     * CRITICAL: Uses $step->state->transitionTo() to ensure:
     * - Transition classes execute (handle() methods with business logic)
     * - Observers fire (StepObserver::updated())
     * - State machine guards enforced (canTransition() checks)
     * - Additional fields set (completed_at, is_throttled, etc.)
     *
     * Previous implementation used DB::table()->update() which bypassed ALL of this.
     */
    public static function batchTransitionSteps(array $stepIds, string $toState): void
    {
        if (empty($stepIds)) {
            log_step('dispatcher', '[batchTransitionSteps] Empty step IDs array - skipping');

            return;
        }

        $stepIdsStr = implode(separator: ', ', array: $stepIds);
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = isset($backtrace[1]) ? ($backtrace[1]['class'] ?? 'unknown').'::'.($backtrace[1]['function'] ?? 'unknown') : 'unknown';

        log_step('dispatcher', '╔═══════════════════════════════════════════════════════════╗');
        log_step('dispatcher', '║         BATCH TRANSITION STEPS                            ║');
        log_step('dispatcher', '╚═══════════════════════════════════════════════════════════╝');
        log_step('dispatcher', 'CALLED BY: '.$caller);
        log_step('dispatcher', 'Target State: '.$toState);
        log_step('dispatcher', 'Step Count: '.count($stepIds));
        log_step('dispatcher', 'Step IDs: ['.$stepIdsStr.']');

        log_step('dispatcher', 'Loading steps and transitioning via state machine...');
        $steps = Step::whereIn('id', $stepIds)->get();
        log_step('dispatcher', 'Loaded '.count($steps).' steps');

        $successCount = 0;
        $failureCount = 0;

        foreach ($steps as $step) {
            log_step($step->id, "Transitioning Step #{$step->id} from {$step->state} to {$toState} via state machine");

            try {
                // Use proper state transition - triggers transition class handle() and observers
                $step->state->transitionTo($toState);
                log_step($step->id, "✓ Step #{$step->id} successfully transitioned to {$toState}");
                $successCount++;
            } catch (\Exception $e) {
                log_step($step->id, "✗ Step #{$step->id} transition FAILED: {$e->getMessage()}");
                log_step($step->id, "  └─ Current state: {$step->state} | Target state: {$toState}");
                $failureCount++;
                // Log but continue - don't fail entire batch due to one invalid transition
            }
        }

        log_step('dispatcher', '✓ Batch transition complete:');
        log_step('dispatcher', "  - Succeeded: {$successCount} steps");
        log_step('dispatcher', "  - Failed: {$failureCount} steps");
        log_step('dispatcher', '╚═══════════════════════════════════════════════════════════╝');
    }

    /**
     * Pre-compute which pending steps can be dispatched using batch operations.
     * Eliminates per-step canTransition() overhead by computing decisions in bulk.
     *
     * @param  \Illuminate\Support\Collection<int, Step>  $pendingSteps
     * @param  array<string, mixed>|null  $stepsCache
     * @return \Illuminate\Support\Collection<int, Step>
     */
    public static function computeDispatchableSteps(
        \Illuminate\Support\Collection $pendingSteps,
        ?array $stepsCache
    ): \Illuminate\Support\Collection {
        if ($pendingSteps->isEmpty() || $stepsCache === null) {
            return collect();
        }

        $parentsByChildBlock = $stepsCache['parents_by_child_block'];
        $stepsByBlockAndIndex = $stepsCache['steps_by_block_and_index'];
        $pendingResolveExceptions = $stepsCache['pending_resolve_exceptions'];

        // Pre-compute concluded indices per block
        $concludedIndicesByBlock = self::computeConcludedIndices($stepsByBlockAndIndex);

        return $pendingSteps->filter(static function (Step $step) use (
            $parentsByChildBlock,
            $concludedIndicesByBlock,
            $pendingResolveExceptions
        ) {
            // Must be in Pending state
            if (! $step->state instanceof Pending) {
                return false;
            }

            // 1. resolve-exception without index → always dispatch
            if ($step->type === 'resolve-exception' && is_null($step->index)) {
                return true;
            }

            // 2. resolve-exception with index → check previous resolve-exception concluded
            if ($step->type === 'resolve-exception' && ! is_null($step->index)) {
                if ($step->index === 1) {
                    return true;
                }
                $key = $step->block_uuid.'_'.($step->index - 1).'_resolve-exception';

                return isset($concludedIndicesByBlock[$key]);
            }

            // Determine step category
            $hasParent = isset($parentsByChildBlock[$step->block_uuid]);
            $isParentStep = ! is_null($step->child_block_uuid);

            // 3. Orphan (no parent, no children)
            if (! $hasParent && ! $isParentStep) {
                if (is_null($step->index)) {
                    return true;
                }

                return self::previousIndexConcludedBatch($step, $concludedIndicesByBlock, $pendingResolveExceptions);
            }

            // 4. Child step → parent must be Running/Completed
            if ($hasParent) {
                $parent = $parentsByChildBlock[$step->block_uuid];
                $parentState = get_class($parent->state);
                if (! in_array($parentState, [Running::class, Completed::class], strict: true)) {
                    return false;
                }

                // Child with null index and parent running → dispatch
                if (is_null($step->index)) {
                    return $parent->state instanceof Running;
                }

                return self::previousIndexConcludedBatch($step, $concludedIndicesByBlock, $pendingResolveExceptions);
            }

            // 5. Parent step
            if ($isParentStep) {
                return self::previousIndexConcludedBatch($step, $concludedIndicesByBlock, $pendingResolveExceptions);
            }

            return false;
        });
    }

    /**
     * Pre-compute which (block_uuid, index, type) combinations are concluded.
     *
     * @return array<string, bool>
     */
    public static function computeConcludedIndices(\Illuminate\Support\Collection $stepsByBlockAndIndex): array
    {
        $concluded = [];

        foreach ($stepsByBlockAndIndex as $key => $steps) {
            // Key format: "block_uuid_index"
            $parts = explode('_', $key);
            $index = array_pop($parts);
            $blockUuid = implode(separator: '_', array: $parts);

            // Check default type steps
            $defaultSteps = $steps->where('type', 'default');
            if ($defaultSteps->isNotEmpty() && $defaultSteps->every(
                static function ($s) {
                    return in_array(get_class($s->state), Step::concludedStepStates(), strict: true);
                }
            )) {
                $concluded[$blockUuid.'_'.$index.'_default'] = true;
            }

            // Check resolve-exception type steps
            $resolveSteps = $steps->where('type', 'resolve-exception');
            if ($resolveSteps->isNotEmpty() && $resolveSteps->every(
                static function ($s) {
                    return in_array(get_class($s->state), Step::concludedStepStates(), strict: true);
                }
            )) {
                $concluded[$blockUuid.'_'.$index.'_resolve-exception'] = true;
            }
        }

        return $concluded;
    }

    /**
     * Check if previous index is concluded using pre-computed data.
     *
     * @param  array<string, bool>  $concludedIndicesByBlock
     * @param  array<string, int>  $pendingResolveExceptions
     */
    public static function previousIndexConcludedBatch(
        Step $step,
        array $concludedIndicesByBlock,
        array $pendingResolveExceptions
    ): bool {
        if ($step->index === 1) {
            return true;
        }

        $type = isset($pendingResolveExceptions[$step->block_uuid]) ? 'resolve-exception' : 'default';
        $key = $step->block_uuid.'_'.($step->index - 1).'_'.$type;

        return isset($concludedIndicesByBlock[$key]);
    }
}

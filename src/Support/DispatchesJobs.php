<?php

declare(strict_types=1);

namespace StepDispatcher\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Throwable;

trait DispatchesJobs
{
    public function dispatchSingleStep(Step $step): void
    {
        log_step($step->id, '╔═══════════════════════════════════════════════════════════╗');
        log_step($step->id, '║         DISPATCHES-JOBS: dispatchSingleStep()            ║');
        log_step($step->id, '╚═══════════════════════════════════════════════════════════╝');
        log_step($step->id, 'Step details:');
        log_step($step->id, '  - Step ID: '.$step->id);
        log_step($step->id, '  - Class: '.($step->class ?? 'NULL'));
        log_step($step->id, '  - Queue: '.$step->queue);
        log_step($step->id, '  - State: '.$step->state);
        log_step($step->id, '  - Arguments: '.json_encode($step->arguments));

        if (empty($step->class)) {
            log_step($step->id, '⚠️ ERROR: Step has no class defined - transitioning to Failed');
            $step->state->transitionTo(Failed::class);
            Log::error("[DispatchesJobs] Step {$step->id} has no class defined.");
            log_step($step->id, '╚═══════════════════════════════════════════════════════════╝');

            return;
        }

        try {
            log_step($step->id, 'Calling instantiateJobWithArguments()...');
            $job = self::instantiateJobWithArguments($step->class, $step->arguments);
            log_step($step->id, 'Job instance created: '.get_class($job));

            log_step($step->id, 'Assigning step to job instance...');
            $job->step = $step;
            log_step($step->id, 'Step assigned to job');

            // Non-functional: improves observability
            $groupLabel = isset($step->group) ? " group={$step->group}" : '';

            if ($step->queue === 'sync') {
                info_if("Calling handle() for Step ID {$step->id}, class {$step->class} on queue {$step->queue}{$groupLabel}");
                log_step($step->id, '→ SYNC QUEUE: Calling handle() directly (synchronous execution)');
                $job->handle();
                log_step($step->id, '← handle() completed (synchronous)');
            } else {
                info_if("Calling Queue::pushOn() for Step ID {$step->id}, class {$step->class} on queue {$step->queue}{$groupLabel}");
                log_step($step->id, '→ ASYNC QUEUE: Calling Queue::pushOn() to queue: '.$step->queue);
                Queue::pushOn($step->queue, $job);
                log_step($step->id, '← Queue::pushOn() completed - job queued');
            }

            log_step($step->id, '✓ dispatchSingleStep() completed successfully');
            log_step($step->id, '╚═══════════════════════════════════════════════════════════╝');
        } catch (Throwable $e) {
            log_step($step->id, '⚠️⚠️⚠️ EXCEPTION CAUGHT IN dispatchSingleStep() ⚠️⚠️⚠️');
            log_step($step->id, 'Exception details:');
            log_step($step->id, '  - Exception class: '.get_class($e));
            log_step($step->id, '  - Exception message: '.$e->getMessage());
            log_step($step->id, '  - Exception file: '.$e->getFile().':'.$e->getLine());

            log_step($step->id, 'Updating step with error information...');
            $step->update(['error_message' => ExceptionParser::with($e)->friendlyMessage()]);
            $step->update(['error_stack_trace' => ExceptionParser::with($e)->stackTrace()]);
            log_step($step->id, 'Error information saved to step');

            // Only transition to Failed if not already in a terminal state
            log_step($step->id, 'Refreshing step to check current state...');
            $step->refresh();
            log_step($step->id, 'Current state after refresh: '.$step->state);

            $terminalStates = Step::terminalStepStates();
            $isTerminal = collect($terminalStates)->contains(static function ($state) use ($step) {
                return $step->state->equals($state);
            });

            log_step($step->id, 'Is terminal state? '.($isTerminal ? 'YES' : 'NO'));

            if (! $isTerminal) {
                log_step($step->id, 'Not terminal - transitioning to Failed...');
                $step->state->transitionTo(Failed::class);
                log_step($step->id, 'Transition to Failed completed');
            } else {
                log_step($step->id, 'Already in terminal state - NOT transitioning to Failed');
            }

            Log::error('[DispatchSingleStep] EXCEPTION: '.$e->getMessage(), [
                'step_id' => $step->id,
                'class' => $step->class,
            ]);
            log_step($step->id, '╚═══════════════════════════════════════════════════════════╝');
        }
    }

    protected static function instantiateJobWithArguments(string $class, ?array $arguments)
    {
        try {
            $arguments ??= [];
            $reflectionClass = new ReflectionClass($class);
            $constructor = $reflectionClass->getConstructor();

            if (is_null($constructor)) {
                return new $class;
            }

            $parameters = $constructor->getParameters();
            $resolvedArguments = [];
            $missingArguments = [];

            foreach ($parameters as $parameter) {
                $name = $parameter->getName();

                if (array_key_exists(key: $name, array: $arguments)) {
                    $resolvedArguments[] = $arguments[$name];
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $resolvedArguments[] = $parameter->getDefaultValue();
                } else {
                    $missingArguments[] = $name;
                }
            }

            if (! empty($missingArguments)) {
                throw new InvalidArgumentException(
                    '[DispatchesJobs] Missing required arguments: '.implode(separator: ', ', array: $missingArguments)." for class {$class}"
                );
            }

            return $reflectionClass->newInstanceArgs($resolvedArguments);
        } catch (ReflectionException $e) {
            throw new RuntimeException("[DispatchesJobs] Failed to instantiate job class {$class}: ".$e->getMessage(), 0, $e);
        }
    }
}

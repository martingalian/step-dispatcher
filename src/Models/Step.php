<?php

declare(strict_types=1);

namespace StepDispatcher\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use StepDispatcher\Abstracts\BaseModel;
use StepDispatcher\Abstracts\StepStatus;
use StepDispatcher\Concerns\Step\HasActions;
use StepDispatcher\Concerns\Step\HasStepLogging;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use Spatie\ModelStates\HasStates;

/**
 * @property int $id
 * @property string $block_uuid
 * @property string $type
 * @property StepStatus $state
 * @property string|null $class
 * @property int|null $index
 * @property array|null $response
 * @property string|null $error_message
 * @property string|null $error_stack_trace
 * @property string|null $relatable_type
 * @property int|null $relatable_id
 * @property string|null $child_block_uuid
 * @property string $execution_mode
 * @property int $double_check
 * @property string $queue
 * @property string $priority
 * @property array|null $arguments
 * @property int $retries
 * @property \Illuminate\Support\Carbon|null $dispatch_after
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int $duration
 * @property string|null $hostname
 * @property bool $was_notified
 * @property string|null $workflow_id
 * @property string|null $canonical
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read string|null $group
 */
final class Step extends BaseModel
{
    use HasActions, HasFactory, HasStates, HasStepLogging;

    protected $guarded = [];

    protected $casts = [
        'arguments' => 'array',
        'response' => 'array',

        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'dispatch_after' => 'datetime',

        'was_throttled' => 'boolean',
        'is_throttled' => 'boolean',

        'state' => StepStatus::class,
    ];

    public static function concludedStepStates()
    {
        return [Completed::class, Skipped::class];
    }

    public static function failedStepStates()
    {
        return [Failed::class, Stopped::class];
    }

    public static function terminalStepStates(): array
    {
        return [
            Completed::class,
            Skipped::class,
            Cancelled::class,
            Failed::class,
            Stopped::class,
        ];
    }

    /**
     * Get a random dispatch group from available groups.
     * Delegates to StepsDispatcher::getDispatchGroup().
     */
    public static function getDispatchGroup(): ?string
    {
        return StepsDispatcher::getDispatchGroup();
    }

    public function stepTick()
    {
        return $this->belongsTo(StepsDispatcherTicks::class, 'tick_id');
    }

    public function scopeDispatchable(Builder $query)
    {
        return $query->where('state', Pending::class)
            ->where('type', 'default');
    }

    public function relatable()
    {
        return $this->morphTo();
    }

    public function scopePending(Builder $query)
    {
        return $query->where('steps.state', Pending::class);
    }

    public function hasChildren(): bool
    {
        if (! $this->isParent()) {
            return false;
        }

        return self::where('block_uuid', $this->child_block_uuid)->exists();
    }

    public function parentStep()
    {
        return self::where('child_block_uuid', $this->block_uuid)->first();
    }

    public function isChild(): bool
    {
        return self::where('child_block_uuid', $this->block_uuid)->exists();
    }

    public function isParent(): bool
    {
        return ! empty($this->child_block_uuid);
    }

    /**
     * A resolve-exception step that is still NotRunnable (never activated).
     * These are inert on success paths and should not block parent completion.
     */
    public function isDormantResolveException(): bool
    {
        return $this->type === 'resolve-exception' && $this->state instanceof NotRunnable;
    }

    public function parentIsRunning(): bool
    {
        $parent = $this->parentStep();

        return $parent && $parent->state->equals(Running::class);
    }

    public function isOrphan(): bool
    {
        return is_null($this->child_block_uuid) && is_null($this->parentStep());
    }

    public function previousIndexIsConcluded()
    {
        log_step($this->id, "[previousIndexIsConcluded] Evaluating previous index for Step ID {$this->id} with index {$this->index} in block {$this->block_uuid}");

        if ($this->index === 1) {
            log_step($this->id, "[previousIndexIsConcluded] Step ID {$this->id} is the first step (index 1), returning true.");

            return true;
        }

        if ($this->index === null && $this->isChild() && $this->parentIsRunning()) {
            log_step($this->id, "[previousIndexIsConcluded] Step ID {$this->id} is a child, its parent is already running, and I dont have an index. Returning true");

            return true;
        }

        $hasPendingResolveException = self::where('block_uuid', $this->block_uuid)
            ->where('type', 'resolve-exception')
            ->where('state', Pending::class)
            ->exists();

        $query = self::where('block_uuid', $this->block_uuid)
            ->where('index', $this->index - 1);

        if ($hasPendingResolveException) {
            $query->where('type', 'resolve-exception');
        } else {
            $query->where('type', 'default');
        }

        $previousSteps = $query->get();

        log_step($this->id, '[previousIndexIsConcluded] Found '.$previousSteps->count()." previous step(s) for Step ID {$this->id}.");

        if ($previousSteps->isEmpty()) {
            log_step($this->id, "[previousIndexIsConcluded] No previous steps found for Step ID {$this->id}, returning false.");

            return false;
        }

        $previousStepsIds = $previousSteps->pluck('id')->implode(',');
        log_step($this->id, 'Previous Steps Ids: '.$previousStepsIds);

        $previousSteps->each(static function ($step) {
            $step->refresh();
            log_step($step->id, "[previousIndexIsConcluded] Previous Step ID {$step->id} has state ".get_class($step->state));
        });

        $result = $previousSteps->every(
            fn ($step) => in_array(get_class($step->state), $this->concludedStepStates(), strict: true)
        );

        if ($result) {
            log_step($this->id, "[previousIndexIsConcluded] All previous steps for Step ID {$this->id} have concluded.");
        } else {
            log_step($this->id, "[previousIndexIsConcluded] Not all previous steps for Step ID {$this->id} have concluded.");
        }

        return $result;
    }

    public function childSteps()
    {
        return $this->hasMany(self::class, 'block_uuid', 'child_block_uuid');
    }

    public function childStepsAreConcludedFromMap($childStepsByBlock): bool
    {
        log_step($this->id, "➡️ [Step.childStepsAreConcludedFromMap] START check for parent ID {$this->id} / child_block_uuid: {$this->child_block_uuid}");

        $children = $childStepsByBlock[$this->child_block_uuid]
        ?? (method_exists($childStepsByBlock, 'get') ? $childStepsByBlock->get($this->child_block_uuid) : null);

        if (empty($children)) {
            log_step($this->id, "⛔ [Step.childStepsAreConcludedFromMap] No children found for block {$this->child_block_uuid}, returning FALSE.");

            return false;
        }

        if (! $children instanceof \Illuminate\Support\Collection) {
            $children = collect($children);
        }

        log_step($this->id, '[Step.childStepsAreConcludedFromMap] 🔍 Found '.$children->count()." children for block {$this->child_block_uuid}");

        foreach ($children as $child) {
            $stateClass = get_class($child->state);
            log_step($child->id, "[Step.childStepsAreConcludedFromMap] 🧒 Child ID {$child->id} | State: ".class_basename($stateClass));

            if ($child->isDormantResolveException()) {
                log_step($child->id, "[Step.childStepsAreConcludedFromMap] 💤 Child ID {$child->id} is dormant resolve-exception, skipping.");

                continue;
            }

            if (! in_array($stateClass, $this->concludedStepStates(), strict: true)) {
                log_step($child->id, "[Step.childStepsAreConcludedFromMap] ❌ Child ID {$child->id} is NOT in concluded states. Returning FALSE.");

                return false;
            }

            if ($child->isParent()) {
                log_step($child->id, "[Step.childStepsAreConcludedFromMap] 🔁 Child ID {$child->id} is a parent. Recursing into its children.");
                $recurse = $child->childStepsAreConcludedFromMap($childStepsByBlock);
                log_step($child->id, "[Step.childStepsAreConcludedFromMap] 🔁 Recursion result for child ID {$child->id}: ".($recurse ? '✅ TRUE' : '❌ FALSE'));
                if (! $recurse) {
                    log_step($child->id, "[Step.childStepsAreConcludedFromMap] ⛔ Recursion failed for child ID {$child->id}. Returning FALSE.");

                    return false;
                }
            }
        }

        log_step($this->id, "[Step.childStepsAreConcludedFromMap] ✅ All children (and grandchildren) of parent ID {$this->id} are concluded. Returning TRUE.");

        return true;
    }

    public function childStepsAreConcluded(): bool
    {
        $children = $this->childSteps()->get();

        if ($children->isEmpty()) {
            return false;
        }

        foreach ($children as $child) {
            if ($child->isDormantResolveException()) {
                continue;
            }

            if (! in_array(get_class($child->state), $this->concludedStepStates(), strict: true)) {
                return false;
            }

            if ($child->isParent() && ! $child->childStepsAreConcluded()) {
                return false;
            }
        }

        return true;
    }

    public function getPrevious()
    {
        return self::where('block_uuid', $this->block_uuid)
            ->where('index', $this->index - 1)
            ->get();
    }

    protected static function newFactory()
    {
        return \StepDispatcher\Database\Factories\StepFactory::new();
    }
}

<?php

declare(strict_types=1);

namespace Pixiekat\LuminaUiBundle\Enum;

/**
 * Lifecycle of a single evaluation run.
 *
 *   Pending   → created, queued, not yet picked up by a worker
 *   Running   → a background worker is executing the docker command
 *   Completed → the command finished with exit code 0 and output was parsed
 *   Failed    → the command errored (non-zero exit, timeout, parse failure)
 *
 * The UI uses this to show a status badge and to decide whether a "re-run"
 * link is offered.
 */
enum EvaluationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** True when the run has reached a final state (no longer in flight). */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}

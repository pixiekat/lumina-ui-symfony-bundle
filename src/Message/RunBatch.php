<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Message;

/**
 * Asks a background worker to run a whole batch — i.e. `search_trials_for_patients`
 * across all patients — and populate the batch's child Evaluation rows.
 */
final readonly class RunBatch {
  public function __construct(
    public int $batchId,
  ) {}
}

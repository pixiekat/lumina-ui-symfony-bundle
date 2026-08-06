<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\ReadModel;

/**
 * TrialEvaluationGroup (read model)
 * =================================
 *
 * One row of the "Trials" index: a trial, plus how many evaluations we have run
 * against it and when we last did.
 *
 * ── Why this exists at all ─────────────────────────────────────────────────
 * The two halves of this row come from two DIFFERENT DATABASES that cannot be
 * joined:
 *
 *   - the counts come from `lumina_db.lumina_evaluation` (Doctrine ORM)
 *   - the trial's name comes from the EXACT database's `trials_trial`
 *     (raw DBAL, via TrialsManager)
 *
 * Something has to stitch them together, and a template is the wrong place: a
 * Twig file that reaches into two managers to assemble a row is a query in
 * disguise, and it will do it once per row. So the controller does the join in
 * PHP — two queries total, regardless of row count — and hands the template a
 * shape it can simply print.
 *
 * That is the general lesson worth keeping: when your data spans systems, the
 * "join" becomes a *read model built in application code*. Same as [[Patient]]
 * and [[Trial]], except those snapshot one external row each, while this one
 * composes across the boundary.
 *
 * `readonly` because nothing should mutate a display row after it is built.
 */
final readonly class TrialEvaluationGroup {

  public function __construct(
    /**
     * The trial id as stored on Evaluation::$trialId. Always present — it is what
     * we grouped by — even when $trial below is null.
     */
    public int $trialId,

    /**
     * The trial record from the EXACT database, or null when it cannot be read.
     *
     * Null is a NORMAL outcome, not an error case: evaluations are historical
     * records that outlive the trials they reference, and TrialsManager
     * deliberately degrades to an empty result when exact_db is unreachable
     * rather than throwing. Losing trial *titles* must not cost you the ability
     * to read your own *results* — so the template falls back to the bare id.
     */
    public ?Trial $trial,

    /** How many evaluation rows exist for this trial, all statuses included. */
    public int $evaluationCount,

    /**
     * When the most recent run for this trial actually FINISHED.
     *
     * Null when every run is still pending — nothing has finished yet, so there
     * is no "last run" to report. Prefer displayDate() over reading this
     * directly; it handles that case.
     */
    public ?\DateTimeImmutable $lastRanAt,

    /**
     * When the most recent run for this trial was QUEUED.
     *
     * Effectively never null (createdAt is set on construction and again on
     * PrePersist), which is what makes it a safe fallback for the date column.
     */
    public ?\DateTimeImmutable $lastQueuedAt,
  ) {}

  /**
   * Builds one group from a raw aggregate row + the matching trial, if we have it.
   *
   * This is the single place that knows how to read what
   * EvaluationRepository::findTrialGroups() returns, and the reason that method's
   * docblock can stop at "callers must convert". Aggregate functions bypass
   * Doctrine's type conversion, so MAX() over a datetime column arrives as a raw
   * driver string rather than a \DateTimeImmutable — everything below deals with
   * that once, here, instead of in every consumer.
   *
   * @param array{trialId: mixed, evaluationCount: mixed, lastRanAt: mixed, lastQueuedAt: mixed} $row
   */
  public static function fromAggregateRow(array $row, ?Trial $trial): self {
    return new self(
      trialId: (int) $row['trialId'],
      trial: $trial,
      // COUNT() comes back as a string on several drivers; cast, do not assume.
      evaluationCount: (int) $row['evaluationCount'],
      lastRanAt: self::toDateTime($row['lastRanAt'] ?? null),
      lastQueuedAt: self::toDateTime($row['lastQueuedAt'] ?? null),
    );
  }

  /**
   * The date to show in the "Last run" column, and whether it is a real run.
   *
   * Returns the completion time when there is one, otherwise the queue time —
   * so a trial whose runs are all still pending shows *something* useful instead
   * of an em dash. The template pairs it with hasFinishedRun() to label which of
   * the two it is; showing a queue time under a heading that says "last run"
   * without saying so would be quietly misleading.
   */
  public function displayDate(): ?\DateTimeImmutable {
    return $this->lastRanAt ?? $this->lastQueuedAt;
  }

  /** Whether at least one run against this trial has actually finished. */
  public function hasFinishedRun(): bool {
    return $this->lastRanAt !== null;
  }

  /**
   * A never-empty label for the trial, degrading gracefully when the EXACT
   * database is unavailable or the trial has been removed.
   *
   * Mirrors Trial::displayTitle()'s contract — a template can always print this
   * and get something a person can act on.
   */
  public function displayTitle(): string {
    return $this->trial?->displayTitle() ?? sprintf('Trial #%d (not in the trials database)', $this->trialId);
  }

  /**
   * Converts one aggregate value into a date, or null.
   *
   * Handles three inputs because the answer legitimately varies by driver and by
   * Doctrine version: an already-converted object (pass it through), a driver
   * string (parse it), or null (no rows contributed a value).
   *
   * An unparseable string degrades to null rather than throwing — consistent with
   * TrialsManager::nullableDate(). One bad timestamp should cost you a cell, not
   * the listing page it sits on.
   */
  private static function toDateTime(mixed $value): ?\DateTimeImmutable {
    if ($value === null || $value === '') {
      return null;
    }

    if ($value instanceof \DateTimeImmutable) {
      return $value;
    }

    // A mutable \DateTime would let a caller change a "readonly" row's date out
    // from under it, so normalise to the immutable form.
    if ($value instanceof \DateTimeInterface) {
      return \DateTimeImmutable::createFromInterface($value);
    }

    try {
      return new \DateTimeImmutable((string) $value);
    }
    catch (\Exception) {
      return null;
    }
  }
}

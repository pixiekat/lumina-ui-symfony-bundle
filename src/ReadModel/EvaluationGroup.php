<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\ReadModel;

/**
 * EvaluationGroup (abstract read model)
 * =====================================
 *
 * The shared half of a "grouped evaluations" row.
 *
 * There are two axes you can slice the evaluation table along, and both produce
 * an identically-shaped listing:
 *
 *   GROUP BY trial_id  → [[TrialEvaluationGroup]]   — /trials
 *   GROUP BY person_id → [[PatientEvaluationGroup]] — /patients
 *
 * Everything except *what the row is about* is common: a count, two aggregate
 * timestamps, and the rules for turning those into something a template can
 * print. That common part lives here so there is exactly ONE copy of the fiddly
 * bit — see toDateTime() for why it is fiddly.
 *
 * ── Why a base class and not a trait or duplication ────────────────────────
 * A trait would share the code but not the TYPE, so a controller could not say
 * "give me any kind of group". An abstract class gives both, and the abstract
 * displayTitle() forces each subclass to answer "what is this row about?" rather
 * than leaving it to a template to guess.
 *
 * `abstract readonly` is deliberate and load-bearing: PHP only lets a readonly
 * class be extended by another readonly class, so declaring the base readonly is
 * what guarantees no subclass can quietly reintroduce mutability.
 */
abstract readonly class EvaluationGroup {

  public function __construct(
    /** How many evaluation rows fall in this group, all statuses included. */
    public int $evaluationCount,

    /**
     * When the most recent run in this group actually FINISHED.
     *
     * Null when every run is still pending — nothing has finished yet, so there
     * is no "last run" to report. Prefer displayDate(), which handles that.
     */
    public ?\DateTimeImmutable $lastRanAt,

    /**
     * When the most recent run in this group was QUEUED.
     *
     * Effectively never null (createdAt is set in Evaluation's constructor AND
     * again on PrePersist), which is what makes it a safe fallback below.
     */
    public ?\DateTimeImmutable $lastQueuedAt,
  ) {}

  /**
   * A never-empty label describing what this row is about.
   *
   * Abstract because it is the ONLY thing that genuinely differs between the two
   * axes — a trial's title versus a patient's name. Every subclass must degrade
   * gracefully when the external record behind it cannot be read, so a template
   * can always print this and get something a person can act on.
   */
  abstract public function displayTitle(): string;

  /**
   * The date for the "Last run" column, and hasFinishedRun() says which it is.
   *
   * Returns the completion time when there is one, otherwise the queue time — so
   * a group whose runs are all still pending shows something useful instead of an
   * em dash. The template pairs the two: printing a queue time under a heading
   * that says "last run", with no note, would be quietly misleading.
   */
  public function displayDate(): ?\DateTimeImmutable {
    return $this->lastRanAt ?? $this->lastQueuedAt;
  }

  /** Whether at least one run in this group has actually finished. */
  public function hasFinishedRun(): bool {
    return $this->lastRanAt !== null;
  }

  /**
   * Converts one value from a GROUP BY row into a date, or null.
   *
   * ── The trap this exists to absorb ─────────────────────────────────────────
   * Aggregate functions bypass Doctrine's type conversion. MAX() over a
   * `datetime_immutable` column does NOT come back as a \DateTimeImmutable — it
   * comes back as whatever the driver produced, which on Postgres is a raw
   * 'YYYY-MM-DD HH:II:SS' string. Hand that straight to a Twig `|date` filter and
   * you get an error, not a date.
   *
   * Three inputs are handled because the answer legitimately varies by driver and
   * by Doctrine version: an already-converted object (pass it through), a driver
   * string (parse it), or null (no row in the group contributed a value).
   *
   * An unparseable string degrades to null rather than throwing, consistent with
   * TrialsManager::nullableDate(). One bad timestamp should cost you a table
   * cell, not the listing page it sits in.
   */
  protected static function toDateTime(mixed $value): ?\DateTimeImmutable {
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

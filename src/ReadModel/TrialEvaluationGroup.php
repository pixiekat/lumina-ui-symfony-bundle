<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\ReadModel;

/**
 * TrialEvaluationGroup (read model)
 * =================================
 *
 * One row of the /trials index: a trial, plus how many evaluations we have run
 * against it and when we last did.
 *
 * The counting and date half lives in [[EvaluationGroup]]; this class adds only
 * what is specific to grouping by TRIAL. Its sibling is
 * [[PatientEvaluationGroup]], which does the same along the patient axis.
 *
 * ── Why a read model exists here at all ────────────────────────────────────
 * The two halves of this row come from two DIFFERENT DATABASES that cannot be
 * joined:
 *
 *   - the counts come from `lumina_db.lumina_evaluation` (Doctrine ORM)
 *   - the trial's name comes from the EXACT database's `trials_trial`
 *     (raw DBAL, via TrialsManager)
 *
 * Something has to stitch them together, and a template is the wrong place: a
 * Twig file that reaches into two managers to assemble a row is a query in
 * disguise, and it will run it once per row. So the controller does the join in
 * PHP — two queries total, regardless of row count — and hands the template a
 * shape it can simply print.
 *
 * That is the general lesson worth keeping: when your data spans systems, the
 * "join" becomes a *read model built in application code*. Same as [[Patient]]
 * and [[Trial]], except those snapshot one external row each, while this one
 * composes across the boundary.
 */
final readonly class TrialEvaluationGroup extends EvaluationGroup {

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

    int $evaluationCount,
    ?\DateTimeImmutable $lastRanAt,
    ?\DateTimeImmutable $lastQueuedAt,
  ) {
    parent::__construct($evaluationCount, $lastRanAt, $lastQueuedAt);
  }

  /**
   * Builds one group from a raw aggregate row + the matching trial, if we have it.
   *
   * The single place that knows how to read what
   * EvaluationRepository::findTrialGroups() returns. See
   * EvaluationGroup::toDateTime() for why the timestamps need converting at all.
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
   * {@inheritDoc}
   *
   * Mirrors Trial::displayTitle()'s contract, with one extra fallback for the
   * case that read model cannot cover: the trial is not readable at all.
   */
  public function displayTitle(): string {
    return $this->trial?->displayTitle() ?? sprintf('Trial #%d (not in the trials database)', $this->trialId);
  }
}

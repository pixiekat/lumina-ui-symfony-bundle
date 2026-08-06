<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\ReadModel;

/**
 * PatientEvaluationGroup (read model)
 * ===================================
 *
 * One row of the /patients index: a patient, plus how many evaluations we have
 * run for them and when we last did.
 *
 * The mirror image of [[TrialEvaluationGroup]] — same aggregate shape from
 * [[EvaluationGroup]], the other axis through the same table. Where that one
 * crosses into the EXACT trials database for a title, this one crosses into
 * ctomop for a name.
 *
 * ── One difference worth knowing about ─────────────────────────────────────
 * The trial axis has to exclude rows with a null `trial_id` (the
 * `search_trials_for_patients` kind has no single trial to group under). The
 * patient axis has no such gap: `person_id` is NOT NULL on every row, so a
 * patient's group legitimately contains BOTH command kinds. The count on this
 * screen therefore means "every run we have for this person", which is what
 * somebody looking up a patient actually wants.
 *
 * ── Why the name is not read from the evaluation row ───────────────────────
 * Evaluation::$patientName exists and would save a query — but it is an
 * `#[Encrypted]` column, so the database cannot aggregate it: MAX() over
 * ciphertext returns whichever blob happens to sort highest, which is not a
 * name and is not even decryptable in context. The name therefore has to come
 * from ctomop, resolved in ONE batched lookup by the controller. A good example
 * of encryption-at-rest quietly changing what SQL can do for you.
 */
final readonly class PatientEvaluationGroup extends EvaluationGroup {

  public function __construct(
    /**
     * The OMOP person_id as stored on Evaluation::$personId. Always present — it
     * is what we grouped by — even when $patient below is null.
     */
    public int $personId,

    /**
     * The patient record from ctomop, or null when it cannot be read.
     *
     * Null is a NORMAL outcome, exactly as on the trial side: an evaluation is a
     * historical record that can outlive the patient it references, and
     * PatientManager degrades to an empty result when ctomop is unreachable
     * rather than throwing. The template falls back to the bare person_id.
     */
    public ?Patient $patient,

    int $evaluationCount,
    ?\DateTimeImmutable $lastRanAt,
    ?\DateTimeImmutable $lastQueuedAt,
  ) {
    parent::__construct($evaluationCount, $lastRanAt, $lastQueuedAt);
  }

  /**
   * Builds one group from a raw aggregate row + the matching patient, if we
   * have it.
   *
   * The single place that knows how to read what
   * EvaluationRepository::findPatientGroups() returns. See
   * EvaluationGroup::toDateTime() for why the timestamps need converting at all.
   *
   * @param array{personId: mixed, evaluationCount: mixed, lastRanAt: mixed, lastQueuedAt: mixed} $row
   */
  public static function fromAggregateRow(array $row, ?Patient $patient): self {
    return new self(
      personId: (int) $row['personId'],
      patient: $patient,
      // COUNT() comes back as a string on several drivers; cast, do not assume.
      evaluationCount: (int) $row['evaluationCount'],
      lastRanAt: self::toDateTime($row['lastRanAt'] ?? null),
      lastQueuedAt: self::toDateTime($row['lastQueuedAt'] ?? null),
    );
  }

  /**
   * {@inheritDoc}
   *
   * Mirrors Patient::displayName()'s contract, with one extra fallback for the
   * case that read model cannot cover: the patient is not readable at all.
   */
  public function displayTitle(): string {
    return $this->patient?->displayName() ?? sprintf('Patient #%d (not in ctomop)', $this->personId);
  }
}

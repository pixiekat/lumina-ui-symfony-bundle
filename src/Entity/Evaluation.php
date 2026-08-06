<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;
use Pixiekat\LuminaUiBundle\Enum\EvaluationKind;
use Pixiekat\LuminaUiBundle\Enum\EvaluationStatus;
use Pixiekat\LuminaUiBundle\Enum\MatchingSoftware;
use Pixiekat\LuminaUiBundle\Repository\EvaluationRepository;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;

/**
 * Evaluation
 * ==========
 *
 * One *row* = one patient's result from one matching run. This single, slightly
 * denormalised shape covers both EXACT command kinds:
 *
 *   - explain_trial_match  → one patient × one trial. `trialId` is set, the
 *     per-attribute breakdown lands in `attributes`, and the overall verdicts
 *     ("CTOMOP: not_eligible / CB: —") land in `summary`.
 *
 *   - search_trials_for_patients → one patient across many trials. `trialId` is
 *     null and `summary` holds the counts/best-scores from the patient's line:
 *       { total, eligible, potential, bestMatchPercent, bestGoodness }
 *
 * A "run all" invocation creates many rows that share a `batchKey`, so the UI
 * can group them later. Patient/trial are stored as plain IDs (no foreign keys)
 * because those records live in the *separate* ctomop / exact databases — this
 * table only references them.
 *
 * The free-form parts (`summary`, `attributes`) are JSON so the model can absorb
 * new fields without a migration. Once the shapes settle, the attribute
 * breakdown is the obvious candidate to normalise into a child table.
 *
 * Composes the shared pixiekat/symfony-common-helpers traits for id + timestamps;
 * HasLifecycleCallbacks lets EntityCreatedAtTrait::setCreatedAtValue (PrePersist)
 * and EntityUpdatedAtTrait::setUpdatedAtValue (PreUpdate) fire automatically.
 */
#[ORM\Entity(repositoryClass: EvaluationRepository::class)]
#[ORM\Table(name: 'lumina_evaluation')]
#[ORM\Index(name: 'idx_eval_person', columns: ['person_id'])]
#[ORM\HasLifecycleCallbacks]
class Evaluation {
  use PixieTraits\EntityIdTrait;
  use PixieTraits\EntityCreatedAtTrait;
  use PixieTraits\EntityUpdatedAtTrait;

  /** Which engine produced this result. */
  #[ORM\Column(length: 32, enumType: MatchingSoftware::class)]
  private MatchingSoftware $software;

  /** Which command kind this row came from (explain vs search). */
  #[ORM\Column(length: 48, enumType: EvaluationKind::class)]
  private EvaluationKind $kind;

  /** CTOMOP patient id, e.g. 1097. Lives in the ctomop DB — stored as a bare id. */
  #[ORM\Column(name: 'person_id')]
  private int $personId;

  /** Patient display name when the command reports it (e.g. "Evelyn Lopez"). */
  #[ORM\Column(length: 255, nullable: true)]
  #[Encrypted]
  private ?string $patientName = null;

  /** Trial id (e.g. 24660). Only set for explain_trial_match. */
  #[ORM\Column(nullable: true)]
  private ?int $trialId = null;

  /** Disease label the command echoes, e.g. "Breast Cancer". */
  #[ORM\Column(length: 128, nullable: true)]
  private ?string $disease = null;

  /** Run lifecycle. New rows start Pending. */
  #[ORM\Column(length: 16, enumType: EvaluationStatus::class)]
  private EvaluationStatus $status = EvaluationStatus::Pending;

  /**
   * Parsed headline result.
   *  - search:  { total, eligible, potential, bestMatchPercent, bestGoodness }
   *  - explain: { overallCtomop, overallCb }
   */
  #[ORM\Column(type: Types::JSON, nullable: true)]
  private ?array $summary = null;

  /**
   * explain_trial_match per-attribute breakdown — a list of:
   *   { name, status, ctomop, cb, differs }
   * Null for search rows.
   */
  #[ORM\Column(type: Types::JSON, nullable: true)]
  private ?array $attributes = null;

  /** The exact shell command used, kept for audit and one-click re-run. */
  #[ORM\Column(type: Types::TEXT, nullable: true)]
  private ?string $command = null;

  /** Full captured stdout, for a "view raw output" panel. */
  #[ORM\Column(type: Types::TEXT, nullable: true)]
  private ?string $rawOutput = null;

  /** Process exit code (0 = success). Null until the run finishes. */
  #[ORM\Column(nullable: true)]
  private ?int $exitCode = null;

  /**
   * Parent batch when this row was produced by a "run all" invocation.
   * Null for one-off single evaluations. onDelete SET NULL keeps the result
   * row around even if its batch record is later deleted.
   */
  #[ORM\ManyToOne(targetEntity: EvaluationBatch::class, inversedBy: 'evaluations')]
  #[ORM\JoinColumn(name: 'batch_id', nullable: true, onDelete: 'SET NULL')]
  private ?EvaluationBatch $batch = null;

  /** When the background run actually finished. Null while pending/running. */
  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $ranAt = null;

  /** Wall-clock duration of the run in milliseconds. */
  #[ORM\Column(nullable: true)]
  private ?int $durationMs = null;

  public function __construct(
    MatchingSoftware $software,
    EvaluationKind $kind,
    int $personId,
  ) {
    $this->software = $software;
    $this->kind = $kind;
    $this->personId = $personId;
    // Belt-and-suspenders: PrePersist also sets this, but seed it now so the
    // value exists before the entity is flushed.
    $this->setCreatedAt(new \DateTimeImmutable());
  }

  // --- Getters / setters ---------------------------------------------------
  // id + created/updated accessors come from the shared traits.

  public function getSoftware(): MatchingSoftware {
    return $this->software;
  }

  public function getKind(): EvaluationKind {
    return $this->kind;
  }

  public function getPersonId(): int {
    return $this->personId;
  }

  public function getPatientName(): ?string {
    return $this->patientName;
  }

  public function setPatientName(?string $patientName): static {
    $this->patientName = $patientName;
    return $this;
  }

  public function getTrialId(): ?int {
    return $this->trialId;
  }

  public function setTrialId(?int $trialId): static {
    $this->trialId = $trialId;
    return $this;
  }

  public function getDisease(): ?string {
    return $this->disease;
  }

  public function setDisease(?string $disease): static {
    $this->disease = $disease;
    return $this;
  }

  public function getStatus(): EvaluationStatus {
    return $this->status;
  }

  public function setStatus(EvaluationStatus $status): static {
    $this->status = $status;
    return $this;
  }

  public function getSummary(): ?array {
    return $this->summary;
  }

  public function setSummary(?array $summary): static {
    $this->summary = $summary;
    return $this;
  }

  public function getAttributes(): ?array {
    return $this->attributes;
  }

  public function setAttributes(?array $attributes): static {
    $this->attributes = $attributes;
    return $this;
  }

  public function getCommand(): ?string {
    return $this->command;
  }

  public function setCommand(?string $command): static {
    $this->command = $command;
    return $this;
  }

  public function getRawOutput(): ?string {
    return $this->rawOutput;
  }

  public function setRawOutput(?string $rawOutput): static {
    $this->rawOutput = $rawOutput;
    return $this;
  }

  public function getExitCode(): ?int {
    return $this->exitCode;
  }

  public function setExitCode(?int $exitCode): static {
    $this->exitCode = $exitCode;
    return $this;
  }

  public function getBatch(): ?EvaluationBatch {
    return $this->batch;
  }

  public function setBatch(?EvaluationBatch $batch): static {
    $this->batch = $batch;
    return $this;
  }

  public function getRanAt(): ?\DateTimeImmutable {
    return $this->ranAt;
  }

  public function setRanAt(?\DateTimeImmutable $ranAt): static {
    $this->ranAt = $ranAt;
    return $this;
  }

  public function getDurationMs(): ?int {
    return $this->durationMs;
  }

  public function setDurationMs(?int $durationMs): static {
    $this->durationMs = $durationMs;
    return $this;
  }

  // --- Derived attribute counts --------------------------------------------
  //
  // `attributes` is the explain_trial_match per-attribute breakdown: a list of
  // { name, status, ctomop, cb, differs } where status is one of
  // "matched" / "not_matched" / "unknown" (see Service\OutputParser::parseExplain).
  //
  // These helpers answer "how well did this patient match?" in ONE number, which
  // is what the per-trial results table sorts on. They live on the entity rather
  // than in a controller or a Twig filter because they are derived purely from
  // this row's own data — anything that holds an Evaluation can ask, and there is
  // exactly one definition of "a match" in the codebase.
  //
  // ── Why this is not a database column ──────────────────────────────────────
  // A stored `matched_count` would be denormalisation: a second copy of a fact
  // the JSON already contains, which can drift if a re-parse ever changes the
  // breakdown. Counting in PHP over a few dozen array entries is free. The point
  // where that stops being true is sorting *across* rows in SQL — see the note on
  // EvaluationRepository::findByTrial() for what to do then.

  /**
   * How many attributes came back with the given parser status.
   *
   * Defensive on every axis, because `attributes` is schemaless JSON: a null
   * column, a non-list payload, or an entry missing its `status` key all count as
   * zero rather than throwing. A malformed row should cost you one number, not
   * the whole page it appears on.
   *
   * @param string $status One of "matched", "not_matched", "unknown".
   */
  public function countAttributesWithStatus(string $status): int {
    if (!is_array($this->attributes)) {
      return 0;
    }

    $count = 0;
    foreach ($this->attributes as $attribute) {
      if (is_array($attribute) && ($attribute['status'] ?? null) === $status) {
        $count++;
      }
    }

    return $count;
  }

  /** Attributes EXACT reported as matching. The headline "match" number. */
  public function getMatchedCount(): int {
    return $this->countAttributesWithStatus('matched');
  }

  /** Attributes EXACT reported as NOT matching. */
  public function getNotMatchedCount(): int {
    return $this->countAttributesWithStatus('not_matched');
  }

  /**
   * Attributes EXACT could not decide on — usually missing CTOMOP data.
   *
   * Worth showing next to the match count rather than folding into "not matched":
   * "3 of 12 matched, 8 unknown" is a data-quality problem, whereas
   * "3 of 12 matched, 8 did not" is a genuine eligibility answer. Collapsing them
   * would hide the difference.
   */
  public function getUnknownCount(): int {
    return $this->countAttributesWithStatus('unknown');
  }

  /** Total attributes in the breakdown — the denominator for the match count. */
  public function getAttributeCount(): int {
    return is_array($this->attributes) ? count($this->attributes) : 0;
  }

  /**
   * The ranking rule for "best result first", as a usort() comparator.
   *
   *     usort($evaluations, Evaluation::compareByMatchQuality(...));
   *
   * Used by BOTH the per-trial and the per-patient results tables, which is
   * exactly why it lives here rather than being written out twice in two
   * controllers: two copies of a sort rule drift, and the two screens would
   * quietly start disagreeing about which run was "best".
   *
   * ── The three tiers, and why each one is needed ────────────────────────────
   *   1. Most matched attributes first. The headline question.
   *   2. Then FEWEST unknowns. A 5-match result built on complete data is a
   *      stronger answer than a 5-match result where half the attributes were
   *      unreadable; without this tier those two sort arbitrarily.
   *   3. Then highest id — newest run — as a final tiebreaker.
   *
   * Tier 3 is not cosmetic. Both screens paginate an array they sorted in PHP, so
   * the order MUST be total: if two equal rows could swap places between the
   * request for page 1 and the request for page 2, a row would appear twice and
   * another would never be seen at all. Any sort that feeds a pager needs a
   * unique final key, and the primary key is the reliable one.
   *
   * `?:` chains here rather than `<=>` alone because each comparison returns 0 on
   * a tie, which is precisely when the next tier should decide.
   */
  public static function compareByMatchQuality(self $a, self $b): int {
    return $b->getMatchedCount() <=> $a->getMatchedCount()
      ?: $a->getUnknownCount() <=> $b->getUnknownCount()
      ?: $b->getId() <=> $a->getId();
  }
}

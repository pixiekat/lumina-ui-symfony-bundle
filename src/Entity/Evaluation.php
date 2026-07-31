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
}

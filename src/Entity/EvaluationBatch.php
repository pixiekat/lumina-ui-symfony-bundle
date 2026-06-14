<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Pixiekat\LuminaUiBundle\Enum\EvaluationKind;
use Pixiekat\LuminaUiBundle\Enum\EvaluationStatus;
use Pixiekat\LuminaUiBundle\Enum\MatchingSoftware;
use Pixiekat\LuminaUiBundle\Repository\EvaluationBatchRepository;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;

/**
 * EvaluationBatch
 * ===============
 *
 * One "run all" invocation. Where an Evaluation is a single patient's result,
 * a batch is the parent that groups the rows produced by one command run
 * (e.g. `search_trials_for_patients` over every patient) so you can come back
 * later and see which batches ran, when, and how they finished.
 *
 * It carries the rollup: overall status, the full stdout, parsed totals
 * (e.g. {processed, errors} from the "Done. Patients processed: 100, Errors: 0"
 * footer), and per-row counts kept in sync as children complete.
 */
#[ORM\Entity(repositoryClass: EvaluationBatchRepository::class)]
#[ORM\Table(name: 'lumina_evaluation_batch')]
#[ORM\HasLifecycleCallbacks]
class EvaluationBatch {
  use PixieTraits\EntityIdTrait;
  use PixieTraits\EntityCreatedAtTrait;
  use PixieTraits\EntityUpdatedAtTrait;

  /** Engine used for the whole batch. */
  #[ORM\Column(length: 32, enumType: MatchingSoftware::class)]
  private MatchingSoftware $software;

  /** Command kind run for the batch (usually SearchTrials / "run all"). */
  #[ORM\Column(length: 48, enumType: EvaluationKind::class)]
  private EvaluationKind $kind;

  /** Overall lifecycle of the batch. */
  #[ORM\Column(length: 16, enumType: EvaluationStatus::class)]
  private EvaluationStatus $status = EvaluationStatus::Pending;

  /** Optional human label, e.g. "Run all — breast cancer cohort". */
  use PixieTraits\EntityLabelTrait;

  /** The shell command used, for audit + re-run. */
  #[ORM\Column(type: Types::TEXT, nullable: true)]
  private ?string $command = null;

  /** Full captured stdout for the whole batch run. */
  #[ORM\Column(type: Types::TEXT, nullable: true)]
  private ?string $rawOutput = null;

  /** Parsed batch footer, e.g. { processed: 100, errors: 0 }. */
  #[ORM\Column(type: Types::JSON, nullable: true)]
  private ?array $summary = null;

  /** Process exit code (0 = success). Null until finished. */
  #[ORM\Column(nullable: true)]
  private ?int $exitCode = null;

  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $startedAt = null;

  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $finishedAt = null;

  /** Wall-clock duration in milliseconds. */
  #[ORM\Column(nullable: true)]
  private ?int $durationMs = null;

  /**
   * The patient-level results produced by this batch.
   *
   * @var Collection<int, Evaluation>
   */
  #[ORM\OneToMany(targetEntity: Evaluation::class, mappedBy: 'batch')]
  private Collection $evaluations;

  public function __construct(
    MatchingSoftware $software,
    EvaluationKind $kind,
  ) {
    $this->software = $software;
    $this->kind = $kind;
    $this->evaluations = new ArrayCollection();
    $this->setCreatedAt(new \DateTimeImmutable());
  }

  // --- Getters / setters ---------------------------------------------------

  public function getSoftware(): MatchingSoftware {
    return $this->software;
  }

  public function getKind(): EvaluationKind {
    return $this->kind;
  }

  public function getStatus(): EvaluationStatus {
    return $this->status;
  }

  public function setStatus(EvaluationStatus $status): static {
    $this->status = $status;
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

  public function getSummary(): ?array {
    return $this->summary;
  }

  public function setSummary(?array $summary): static {
    $this->summary = $summary;
    return $this;
  }

  public function getExitCode(): ?int {
    return $this->exitCode;
  }

  public function setExitCode(?int $exitCode): static {
    $this->exitCode = $exitCode;
    return $this;
  }

  public function getStartedAt(): ?\DateTimeImmutable {
    return $this->startedAt;
  }

  public function setStartedAt(?\DateTimeImmutable $startedAt): static {
    $this->startedAt = $startedAt;
    return $this;
  }

  public function getFinishedAt(): ?\DateTimeImmutable {
    return $this->finishedAt;
  }

  public function setFinishedAt(?\DateTimeImmutable $finishedAt): static {
    $this->finishedAt = $finishedAt;
    return $this;
  }

  public function getDurationMs(): ?int {
    return $this->durationMs;
  }

  public function setDurationMs(?int $durationMs): static {
    $this->durationMs = $durationMs;
    return $this;
  }

  /** @return Collection<int, Evaluation> */
  public function getEvaluations(): Collection {
    return $this->evaluations;
  }

  public function addEvaluation(Evaluation $evaluation): static {
    if (!$this->evaluations->contains($evaluation)) {
      $this->evaluations->add($evaluation);
      $evaluation->setBatch($this);
    }
    return $this;
  }

  public function removeEvaluation(Evaluation $evaluation): static {
    if ($this->evaluations->removeElement($evaluation)) {
      // unset the owning side if it still points here
      if ($evaluation->getBatch() === $this) {
        $evaluation->setBatch(null);
      }
    }
    return $this;
  }

  /** Convenience: how many child evaluations exist. */
  public function getEvaluationCount(): int {
    return $this->evaluations->count();
  }
}

<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\ReadModel;

/**
 * Trial (read model)
 * ==================
 *
 * An immutable snapshot of one EXACT clinical trial, read from `trials_trial`.
 * The sibling of [[Patient]], and deliberately NOT a Doctrine entity for the
 * same reasons: the schema belongs to EXACT's Django migrations, we only ever
 * read it, and keeping it unmapped means no Doctrine tooling can ever propose
 * DDL against somebody else's database.
 *
 * `trials_trial` is a very wide table — well over a hundred columns, most of
 * them eligibility thresholds (ecog_performance_status_max, hemoglobin_level_min,
 * liver_enzyme_level_alt_uln_max …). This read model deliberately carries only
 * the *identifying and descriptive* fields a UI needs to say "which trial is
 * this". The eligibility criteria are what EXACT's matching engine consumes; if
 * a screen ever needs to show them, they belong in a second, purpose-built read
 * model rather than bloating this one. A read model should answer one question.
 *
 * Nullability note: several columns are NOT NULL in Postgres but hold the empty
 * string when unknown — Django's convention for text fields. TrialsManager
 * normalises '' to null on the way in, so "not recorded" is a single concept
 * here rather than two that templates have to test separately.
 */
final readonly class Trial {

  public function __construct(
    /** `trials_trial.id` — the id Evaluation::$trialId stores. */
    public int $id,

    /**
     * `study_id` — the registry identifier, e.g. "NCT06150664". This is the
     * number a human actually recognises a trial by; prefer showing it over the
     * internal id wherever there is room for one of them.
     */
    public ?string $studyId = null,

    /** `brief_title` — the short public title. */
    public ?string $briefTitle = null,

    /** `official_title` — the full protocol title, often very long. */
    public ?string $officialTitle = null,

    /** `recruitment_status`, e.g. "RECRUITING". Registry vocabulary, uppercase. */
    public ?string $recruitmentStatus = null,

    /** `register` — source registry, e.g. "clinicaltrials.gov". */
    public ?string $register = null,

    /** `study_type`, e.g. "INTERVENTIONAL". */
    public ?string $studyType = null,

    /**
     * `phases` — a jsonb array of registry tokens, e.g. ["PHASE1"] or ["NA"].
     * Decoded to a PHP list by the manager; see phaseLabel() for display.
     *
     * @var string[]
     */
    public array $phases = [],

    /** `sponsor_name`, e.g. "AstraZeneca". */
    public ?string $sponsorName = null,

    /** `disease` — free text, e.g. "breast cancer". */
    public ?string $disease = null,

    /** `link` — canonical registry URL for the study. */
    public ?string $link = null,

    /** `age_low_limit` — minimum eligible age in years. */
    public ?int $ageLowLimit = null,

    /** `age_high_limit` — maximum eligible age in years; usually unset. */
    public ?int $ageHighLimit = null,

    /**
     * `gender` — a 3-char eligibility code. Null for the overwhelming majority
     * of rows, which means "all genders eligible", not "unknown".
     */
    public ?string $gender = null,

    /** `target_sample_size` — planned enrolment. */
    public ?int $targetSampleSize = null,

    /** `posted_date` — when the registry first published the study. */
    public ?\DateTimeImmutable $postedDate = null,

    /** `last_update_date` — most recent registry revision. */
    public ?\DateTimeImmutable $lastUpdateDate = null,
  ) {}

  /**
   * A title that is never empty, degrading through brief → official → registry
   * id → internal id. Same contract as Patient::displayName(): a template can
   * always print this and get something a person can act on.
   */
  public function displayTitle(): string {
    return $this->briefTitle
      ?? $this->officialTitle
      ?? $this->studyId
      ?? sprintf('Trial #%d', $this->id);
  }

  /**
   * Human-readable phase, e.g. "Phase 1", "Phase 1/Phase 2", "Not applicable".
   *
   * The registry stores machine tokens ("PHASE1", "NA"). Printing those raw is
   * an accessibility problem, not just an aesthetic one: a screen reader says
   * "P-H-A-S-E-one" and an unfamiliar reader has to decode shouty jargon. We
   * expand them once, here, so every template gets the readable form for free.
   */
  public function phaseLabel(): ?string {
    if ($this->phases === []) {
      return null;
    }

    $labels = array_map(
      static fn(string $phase): string => match (strtoupper($phase)) {
        'NA', 'N/A' => 'Not applicable',
        'EARLY_PHASE1', 'EARLY PHASE 1' => 'Early phase 1',
        'PHASE1' => 'Phase 1',
        'PHASE2' => 'Phase 2',
        'PHASE3' => 'Phase 3',
        'PHASE4' => 'Phase 4',
        // Unrecognised tokens pass through rather than vanishing — a new
        // registry value should look odd, not silently disappear.
        default => $phase,
      },
      $this->phases,
    );

    // Multi-phase studies really are recorded as ["PHASE1","PHASE2"].
    return implode('/', $labels);
  }

  /**
   * Eligible age range as a sentence fragment, e.g. "18 and over", "12 to 65",
   * "up to 70", or null when the trial sets no limits.
   *
   * Written out in words rather than as "18–" or "18-65" because an en dash is
   * read aloud inconsistently, and a trailing dash reads as nothing at all.
   */
  public function ageRangeLabel(): ?string {
    return match (true) {
      $this->ageLowLimit !== null && $this->ageHighLimit !== null
        => sprintf('%d to %d', $this->ageLowLimit, $this->ageHighLimit),
      $this->ageLowLimit !== null => sprintf('%d and over', $this->ageLowLimit),
      $this->ageHighLimit !== null => sprintf('up to %d', $this->ageHighLimit),
      default => null,
    };
  }

  /**
   * Whether the trial is currently recruiting.
   *
   * Compared case-insensitively because the registry vocabulary is uppercase but
   * nothing guarantees every import preserves that.
   */
  public function isRecruiting(): bool {
    return strcasecmp($this->recruitmentStatus ?? '', 'RECRUITING') === 0;
  }
}

<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\ReadModel;

/**
 * Patient (read model)
 * ====================
 *
 * A flat, immutable snapshot of one ctomop patient, assembled from a join of
 * `person` + `patient_info`. It is deliberately NOT a Doctrine entity:
 *
 *   - ctomop's schema is owned by somebody else's Django migrations. If we
 *     mapped it, a `manage.py migrate` over there could silently invalidate our
 *     mapping, and `doctrine:migrations:diff` on our side would start proposing
 *     DDL against a database we have no business changing.
 *   - There is nothing to persist. We only ever read.
 *   - An entity implies identity + change tracking in a UnitOfWork. A snapshot
 *     handed to a Twig template needs neither, and skipping them means no
 *     accidental flush can ever reach ctomop.
 *
 * "Read model" is the CQRS term for exactly this: a shape optimised for display
 * rather than for storage. Ours mirrors what an evaluations screen wants to
 * show, not what OMOP happens to normalise into forty tables.
 *
 * Everything except `personId` is nullable, because it genuinely is. The
 * synthetic dataset currently populates names, year_of_birth, disease, stage,
 * ECOG and Karnofsky, but leaves gender concepts and `patient_age` empty — so
 * the UI has to degrade gracefully rather than assume. Use the `has*()` helpers
 * (or Twig's own null checks) instead of printing bare nulls.
 *
 * `readonly` (PHP 8.2 class-level) means the object cannot be mutated after
 * construction, which is what makes it safe to pass around and cache.
 */
final readonly class Patient {

  public function __construct(
    /** OMOP `person.person_id` — the id Evaluation::$personId stores. */
    public int $personId,

    /** `person.given_name` — first name. */
    public ?string $givenName = null,

    /** `person.family_name` — surname. */
    public ?string $familyName = null,

    /** `person.year_of_birth`. Month/day exist in OMOP but are often blank. */
    public ?int $yearOfBirth = null,

    /**
     * `patient_info.patient_age` when the source recorded it. Currently null
     * across the whole synthetic dataset — prefer approximateAge().
     */
    public ?int $recordedAge = null,

    /**
     * `patient_info.gender` (a 2-char code) falling back to
     * `person.gender_source_value`. Both are empty in the current data; the
     * OMOP-correct answer would join `concept` on gender_concept_id, but that
     * column is null for every row too. Kept so the UI is ready when it lands.
     */
    public ?string $gender = null,

    /** `patient_info.ethnicity` — free text, e.g. "Native American". */
    public ?string $ethnicity = null,

    /** `patient_info.disease`, e.g. "Breast Cancer". */
    public ?string $disease = null,

    /** `patient_info.stage`, e.g. "IIB", "IV". */
    public ?string $stage = null,

    /** `patient_info.ecog_performance_status` — 0..5, lower is better. */
    public ?int $ecogPerformanceStatus = null,

    /** `patient_info.karnofsky_performance_score` — 0..100, higher is better. */
    public ?int $karnofskyPerformanceScore = null,
  ) {}

  /**
   * A display name that never comes back empty.
   *
   * Falls back through "Given Family" → whichever half exists → "Patient 1000",
   * so a template can always print something meaningful. Screen readers get a
   * real label rather than a stray "#" or an empty cell.
   */
  public function displayName(): string {
    $name = trim(sprintf('%s %s', $this->givenName ?? '', $this->familyName ?? ''));

    return $name !== '' ? $name : sprintf('Patient %d', $this->personId);
  }

  /**
   * Best-effort age in years.
   *
   * Prefers the recorded value; otherwise derives from year_of_birth. This is
   * an approximation by construction — with no month/day we can be off by one —
   * so anything clinical should say "approx." next to it rather than implying a
   * precision the data does not have.
   *
   * @param \DateTimeImmutable|null $asOf Reference date; defaults to today.
   *   Passing it in explicitly keeps this method testable (no hidden clock).
   */
  public function approximateAge(?\DateTimeImmutable $asOf = null): ?int {
    if ($this->recordedAge !== null) {
      return $this->recordedAge;
    }

    if ($this->yearOfBirth === null) {
      return null;
    }

    $year = (int) ($asOf ?? new \DateTimeImmutable())->format('Y');

    return max(0, $year - $this->yearOfBirth);
  }

  /**
   * True when we know enough to render a meaningful clinical summary line.
   * Lets a template choose between a real summary and an honest "no clinical
   * data recorded" message, instead of rendering a row of em-dashes.
   */
  public function hasClinicalSummary(): bool {
    return $this->disease !== null
      || $this->stage !== null
      || $this->ecogPerformanceStatus !== null;
  }
}

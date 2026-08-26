<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Enum;

/**
 * MatchingType
 * ============
 *
 * EXACT's verdict on one patient against one trial, as returned in the
 * `matchingType` field of GET /trials/ (and its POST alias /trials/match/).
 *
 * ── Why an enum and not the raw string ──────────────────────────────────────
 * The API hands back lowercase snake_case tokens. Left as strings they spread
 * through the codebase as magic values, and the day EXACT adds a fourth status
 * the only symptom is a comparison that quietly never matches. A backed enum
 * turns that into one failing `tryFrom()` in one place, and gives templates a
 * label method instead of asking Twig to know the vocabulary.
 *
 * ── The three-way distinction actually matters clinically ───────────────────
 * This is NOT eligible/ineligible with a maybe bucket bolted on. The middle
 * value has a specific, load-bearing meaning:
 *
 *   Eligible     — every criterion EXACT could evaluate came back matched.
 *   Potential    — nothing DISQUALIFIED the patient, but at least one criterion
 *                  could not be evaluated because ctomop has no value for it.
 *                  The trial's `attributesToFillIn` names exactly what is
 *                  missing. This is "we don't know yet", never "probably not".
 *   NotEligible  — at least one criterion actively ruled the patient out.
 *
 * Collapsing Potential into NotEligible would hide trials from a clinician
 * because *our* data is thin, which is the wrong failure direction on a screen
 * whose whole job is finding candidate trials. Most of the synthetic ctomop
 * patients have sparse clinical records, so in practice Potential is where the
 * bulk of the interesting rows live.
 */
enum MatchingType: string {

  /** Every evaluable criterion matched. */
  case Eligible = 'eligible';

  /** No disqualifier found, but data is missing — see `attributesToFillIn`. */
  case Potential = 'potential';

  /** At least one criterion actively excluded the patient. */
  case NotEligible = 'not_eligible';

  /**
   * The value to send as `?type=` when asking the API for just this status.
   *
   * Identical to ->value today, and deliberately its own method anyway: the
   * query vocabulary is EXACT's wire contract, while ->value is ours. If they
   * ever diverge (a renamed filter, an alias), this is the single seam that
   * absorbs it instead of a find-and-replace across the service.
   */
  public function queryValue(): string {
    return $this->value;
  }

  /**
   * A human label for templates.
   *
   * Sentence case, not the shouty token. Same reasoning as Trial::phaseLabel():
   * "not_eligible" is read aloud by a screen reader as an underscore-separated
   * mess, and a clinician should never have to decode our wire format.
   */
  public function label(): string {
    return match ($this) {
      self::Eligible => 'Eligible',
      self::Potential => 'Possible — data missing',
      self::NotEligible => 'Not eligible',
    };
  }

  /**
   * A short CSS modifier suffix, e.g. for `.match-badge--eligible`.
   *
   * Kept here so the enum owns its own presentation vocabulary and no template
   * has to build class names out of raw API strings.
   */
  public function cssModifier(): string {
    return match ($this) {
      self::Eligible => 'eligible',
      self::Potential => 'potential',
      self::NotEligible => 'not-eligible',
    };
  }

  /**
   * Whether this status belongs in the narrowed "candidate trials" list.
   *
   * The one place the eligible + potential policy decision is written down. If
   * the screen should ever tighten to eligible-only, this method changes and
   * nothing else does.
   */
  public function isCandidate(): bool {
    return $this === self::Eligible || $this === self::Potential;
  }

  /**
   * The statuses the patient-first screen asks EXACT for, in display order.
   *
   * Order is deliberate and not alphabetical: confirmed matches first, then the
   * ones needing a data chase. Within each bucket the API's own matchScore
   * ordering is preserved by the caller.
   *
   * @return self[]
   */
  public static function candidates(): array {
    return [self::Eligible, self::Potential];
  }

  /**
   * Parses an API token into a case, or null for anything unrecognised.
   *
   * Null rather than an exception on purpose, and this is the graceful
   * degradation call for this file: a status EXACT adds next year should cost
   * that ONE row its badge, not take out the whole trial list with a
   * ValueError. The caller logs the unknown token so it still gets noticed.
   */
  public static function tryFromApi(?string $value): ?self {
    if ($value === null || $value === '') {
      return null;
    }

    return self::tryFrom(strtolower(trim($value)));
  }
}

<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Enum;

/**
 * MatchFilter
 * ===========
 *
 * Which match verdicts the patient-first screen asks EXACT for. The user-facing
 * policy choice, as distinct from [[MatchingType]], which is EXACT's per-trial
 * answer.
 *
 * The two are easy to conflate, so: MatchingType is a fact about one trial
 * ("this one is eligible"). MatchFilter is a question we choose to ask ("show me
 * the eligible ones"). One is data, one is intent, and only the second belongs
 * in a query string.
 *
 * ── Why this is switchable at all ───────────────────────────────────────────
 * The clinically safe default is eligible + potential, because a Potential match
 * means "no disqualifier found, but ctomop is missing data" — hiding those would
 * drop trials from a clinician's view on the strength of OUR record-keeping
 * rather than the patient's actual fit. See MatchingType's docblock.
 *
 * But "safe default" is a claim worth being able to check rather than assert,
 * and the three positions make the cost of the policy visible: if eligible-only
 * shows 12 trials and eligible+potential shows 92, that gap is exactly the set
 * of trials whose fate depends on a missing ctomop field. Being able to see that
 * number is the point.
 *
 * It also fits what this screen already is. The patient-first and trial-first
 * flows exist side by side specifically so they can be compared in use; this is
 * the same instinct applied one level down.
 *
 * ── KNOWN UPSTREAM LIMITATION (verified 2026-08-26) ─────────────────────────
 * On the current dev deployment this switch changes NOTHING, and that is worth
 * knowing before anyone spends an afternoon debugging it here.
 *
 * EXACT's `?type=` parameter is inert. Measured across all pages for one
 * patient, `type=eligible`, `type=potential` and `type=not_eligible` return
 * byte-identical sets — the same 82 trial ids — and every returned row carries
 * `matchingType: "eligible"` regardless. No result is ever labelled `potential`
 * or `not_eligible`, so there is nothing for the filter to select between.
 *
 * That is very likely downstream of the same deployment gap that breaks
 * /normalize-ctomop-row/: EXACT serves trials from `ne_bc_trials`, which is
 * missing trials_marker, trials_markercategory and trials_markercategoryconnection.
 * Criterion evaluation is what produces the three-way verdict, and the marker
 * tables are what criteria are built from.
 *
 * This enum is therefore written against the DOCUMENTED contract rather than the
 * current behaviour, on purpose. When the marker tables land, the switch starts
 * discriminating with no change here. Until then it is also the instrument for
 * noticing that it has: three positions showing three identical counts is the
 * symptom, and three different counts is the all-clear.
 */
enum MatchFilter: string {

  /** Confirmed fits only — every evaluable criterion matched. */
  case Eligible = 'eligible';

  /** Only the ones blocked on missing patient data. The gap, in isolation. */
  case Potential = 'potential';

  /** The clinically safe default. Both of the above. */
  case EligibleAndPotential = 'eligible-potential';

  /**
   * The MatchingType cases this filter asks EXACT for.
   *
   * Order matters and is preserved by the matcher: eligible before potential
   * means confirmed fits sort above data-gap fits without anyone sorting.
   *
   * @return MatchingType[]
   */
  public function statuses(): array {
    return match ($this) {
      self::Eligible => [MatchingType::Eligible],
      self::Potential => [MatchingType::Potential],
      self::EligibleAndPotential => MatchingType::candidates(),
    };
  }

  /** Short label for the toggle control. */
  public function label(): string {
    return match ($this) {
      self::Eligible => 'Eligible only',
      self::Potential => 'Possible only',
      self::EligibleAndPotential => 'Eligible + possible',
    };
  }

  /**
   * A sentence explaining what this position shows, for the toggle's help text.
   *
   * Written for a clinician rather than a developer: no "matchingType", no
   * enum names, and the consequence of the choice stated rather than implied.
   */
  public function description(): string {
    return match ($this) {
      self::Eligible => 'Trials where every criterion we could check matched. Narrowest — trials blocked only by missing data are hidden.',
      self::Potential => 'Only trials blocked by missing patient data. Use this to see what recording more in ctomop would unlock.',
      self::EligibleAndPotential => 'Confirmed fits plus trials where nothing ruled the patient out but some data is missing. The default.',
    };
  }

  /**
   * Parses a query-string or environment value, falling back to the safe default.
   *
   * Never throws. A bad `?match=` in a URL — hand-edited, stale bookmark, a typo
   * in .env — must not 500 a clinical screen, and silently widening to the safe
   * default is the right direction to fail: the user sees MORE trials than they
   * asked for, never fewer. Underscores are accepted alongside hyphens because
   * both spellings are natural to type.
   */
  public static function fromRequest(?string $value): self {
    if ($value === null) {
      return self::EligibleAndPotential;
    }

    $value = str_replace('_', '-', strtolower(trim($value)));

    return self::tryFrom($value) ?? self::EligibleAndPotential;
  }
}

<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\ReadModel;

use Pixiekat\LuminaUiBundle\Enum\MatchingType;

/**
 * TrialMatch (read model)
 * =======================
 *
 * EXACT's verdict on ONE trial for ONE patient, as returned by
 * `POST /exact/trials/match/`. The third read model alongside [[Patient]] and
 * [[Trial]], and like both of them: immutable, hand-built by a service, never
 * wired by the container, never a Doctrine entity.
 *
 * ── Why this is not just extra fields on Trial ──────────────────────────────
 * Trial's own docblock sets the rule this file obeys: *"A read model should
 * answer one question."* Trial answers "which trial is this" and is a snapshot
 * of a database row. This answers "how does this trial relate to THIS patient"
 * and is a snapshot of a matching-engine response for a (patient, trial) pair.
 *
 * They have genuinely different lifetimes, which is the practical tell that
 * they are different models. A Trial is valid until EXACT re-imports the
 * registry. A TrialMatch is valid only for the patient it was computed for, and
 * is stale the moment that patient's ctomop record changes. Merging them would
 * produce an object where half the fields silently depend on a patient that the
 * object does not name — the sort of thing that reads fine and caches wrong.
 *
 * So this model carries the match facts plus `trialId`, and the caller pairs it
 * with the descriptive Trial via TrialsManagerInterface::findMany(). Titles,
 * phases and sponsors keep coming from the database we control; only the
 * *ranking* comes over HTTP.
 *
 * ── Nullability is meaningful here, not incidental ──────────────────────────
 * `matchScore`, `distance` and `matchReasons` are all legitimately null:
 *
 *   matchScore    null when the request carried no patient context at all.
 *   distance      null unless the patient profile supplied a location.
 *   matchReasons  null unless the request passed ?explain=true.
 *
 * None of those mean zero, and none of them mean "no match". `(int) null` is 0,
 * and a 0 in a column headed "match score" is a lie a clinician might act on —
 * the same trap PatientManager::hydrate() documents for ECOG. Nulls stay null
 * all the way to the template, which prints an em dash.
 */
final readonly class TrialMatch {

  public function __construct(
    /**
     * `trialId` — EXACT's internal id, the same key as `trials_trial.id` and
     * therefore the same key as Trial::$id and Evaluation::$trialId. This is the
     * join column that lets the controller pair a match with its Trial.
     */
    public int $trialId,

    /** `studyId`, e.g. "NCT07723209". Carried for logging and sanity-checking
     *  a pairing without needing the Trial in hand. */
    public ?string $studyId = null,

    /**
     * `matchingType` — the three-way verdict. Null when EXACT returned a token
     * this bundle does not recognise; the client logs that case rather than
     * throwing, so one unfamiliar status costs a badge and not the page.
     */
    public ?MatchingType $matchingType = null,

    /**
     * `matchScore` — 0-100, higher is better, null when unscored. NOT the same
     * number as goodnessScore: this one is patient-relative.
     */
    public ?int $matchScore = null,

    /**
     * `goodnessScore` — EXACT's patient-independent quality score for the trial
     * itself (design, sponsor, enrolment and so on). Identical for every patient,
     * and the API's default sort. Kept because it is the sensible tiebreaker
     * when two trials share a matchScore.
     */
    public ?int $goodnessScore = null,

    /**
     * `patientBurdenScore` — how demanding participation is (visits, procedures).
     * Lower is gentler. Worth surfacing next to the match score: the best-matching
     * trial is not automatically the one to put in front of a frail patient.
     */
    public ?int $patientBurdenScore = null,

    /**
     * `distance` to the nearest site, in `distanceUnits`. Null unless the patient
     * profile carried a location — see the class docblock.
     */
    public ?float $distance = null,

    /** `distanceUnits`, e.g. "miles". Null whenever $distance is. */
    public ?string $distanceUnits = null,

    /**
     * `attributesToFillIn` — the criteria EXACT could not evaluate because the
     * patient record has no value for them.
     *
     * This is the actionable half of a Potential match, and the reason Potential
     * earns its place in the list: each entry names a specific gap
     * ("Stem Cell Transplant History"), so the screen can tell a clinician what
     * to go and record rather than just showing a shrug.
     *
     * Kept as raw associative arrays rather than yet another read model — they
     * are display-only strings the template prints in a list, and a class per
     * API sub-object is ceremony without a payer. Promote it the moment
     * something needs to reason about them rather than print them.
     *
     * Each entry has: trialAttributeName, userAttributeName, userAttributeTitle,
     * count.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $attributesToFillIn = [],

    /**
     * `matchReasons` — per-criterion breakdown, present only with ?explain=true.
     *
     * Null and empty-array are DIFFERENT here and the distinction is load-bearing:
     * null means "we did not ask for an explanation", [] means "we asked and
     * EXACT found no relevant criteria". A template that shows "no reasons given"
     * for the first case and "no criteria apply" for the second is telling the
     * truth in both; one that treats them alike is guessing in one of them.
     *
     * Each entry has: attr, status (matched|unknown|not_matched), patientValue,
     * trialRequirement.
     *
     * @var array<int, array<string, mixed>>|null
     */
    public ?array $matchReasons = null,
  ) {}

  /**
   * Whether this match belongs in the narrowed candidate list.
   *
   * Delegates to the enum so the eligible + potential policy lives in exactly
   * one place. An unrecognised status (null) is treated as NOT a candidate —
   * conservative, and it keeps a future EXACT status from silently appearing in
   * a clinical list before anyone has decided what it means.
   */
  public function isCandidate(): bool {
    return $this->matchingType?->isCandidate() ?? false;
  }

  /**
   * How many distinct patient attributes are missing for this trial.
   *
   * Note this counts ATTRIBUTES, not the `count` field inside each entry — that
   * one is EXACT's own tally of how many trial criteria depend on the attribute,
   * which is a different question. Naming this method for what it returns keeps
   * the two from being confused at the call site.
   */
  public function missingAttributeCount(): int {
    return count($this->attributesToFillIn);
  }

  /**
   * The human titles of the missing attributes, for a "needs: X, Y" line.
   *
   * Falls back through title → userAttributeName → trialAttributeName so a
   * malformed entry still prints something a person can search for, rather than
   * an empty string. Entries with none of the three are dropped entirely.
   *
   * @return string[]
   */
  public function missingAttributeTitles(): array {
    $titles = [];

    foreach ($this->attributesToFillIn as $attribute) {
      $title = $attribute['userAttributeTitle']
        ?? $attribute['userAttributeName']
        ?? $attribute['trialAttributeName']
        ?? null;

      if (is_string($title) && trim($title) !== '') {
        $titles[] = trim($title);
      }
    }

    return $titles;
  }

  /**
   * Distance as a readable fragment, e.g. "12.3 miles", or null when unknown.
   *
   * Rounded to one decimal because EXACT returns full float precision and
   * "12.300000000000001 miles" is noise. Units come from the API rather than
   * being assumed: the field exists precisely because it is not always miles.
   */
  public function distanceLabel(): ?string {
    if ($this->distance === null) {
      return null;
    }

    return sprintf('%s %s', round($this->distance, 1), $this->distanceUnits ?? 'away');
  }

  /**
   * Builds a TrialMatch from one decoded `results[]` entry.
   *
   * Lives here rather than in the client for the same reason TrialsManager keeps
   * hydrate() private and single: one place owns the wire→model mapping, so an
   * upstream rename is a one-method fix. It is static and pure, which also makes
   * it trivial to unit-test against a captured JSON fixture with no HTTP at all.
   *
   * Defensive throughout. Every field except trialId degrades to null rather
   * than throwing, because the alternative is one odd row taking out a whole
   * clinical listing. A missing or non-numeric trialId is the single fatal case
   * — without the join key the row cannot be paired with a Trial, so there is
   * genuinely nothing to render.
   *
   * @param array<string, mixed> $row One entry from the API's `results` array.
   * @throws \InvalidArgumentException When `trialId` is absent or not numeric.
   */
  public static function fromApiResult(array $row): self {
    $trialId = $row['trialId'] ?? null;

    if (!is_numeric($trialId)) {
      throw new \InvalidArgumentException(sprintf(
        'EXACT match result has no usable trialId (got %s).',
        get_debug_type($trialId),
      ));
    }

    return new self(
      trialId: (int) $trialId,
      studyId: self::nullableString($row['studyId'] ?? null),
      // tryFromApi() rather than from(): an unknown status yields null and is
      // logged by the caller, instead of a ValueError killing the request.
      matchingType: MatchingType::tryFromApi(self::nullableString($row['matchingType'] ?? null)),
      matchScore: self::nullableInt($row['matchScore'] ?? null),
      goodnessScore: self::nullableInt($row['goodnessScore'] ?? null),
      patientBurdenScore: self::nullableInt($row['patientBurdenScore'] ?? null),
      distance: self::nullableFloat($row['distance'] ?? null),
      distanceUnits: self::nullableString($row['distanceUnits'] ?? null),
      attributesToFillIn: self::objectList($row['attributesToFillIn'] ?? null),
      // The null/[] distinction documented on the property: only coerce to a
      // list when the key actually carried an array.
      matchReasons: is_array($row['matchReasons'] ?? null)
        ? self::objectList($row['matchReasons'])
        : null,
    );
  }

  // ── Internals ───────────────────────────────────────────────────────────────

  /** Trims, then treats an empty string as "not recorded". */
  private static function nullableString(mixed $value): ?string {
    if (!is_scalar($value)) {
      return null;
    }

    $value = trim((string) $value);

    return $value === '' ? null : $value;
  }

  /** Null stays null. See the class docblock for why 0 is not an acceptable stand-in. */
  private static function nullableInt(mixed $value): ?int {
    return is_numeric($value) ? (int) $value : null;
  }

  /** As nullableInt(), for the one field EXACT sends as a float. */
  private static function nullableFloat(mixed $value): ?float {
    return is_numeric($value) ? (float) $value : null;
  }

  /**
   * Normalises a JSON array-of-objects into a clean list of associative arrays.
   *
   * Drops anything that is not an array, so a scalar smuggled into the list
   * cannot reach a template expecting to index into it. array_values() re-indexes
   * so a JSON *object* rather than an array still yields a list — the same
   * defence TrialsManager::jsonStringList() applies to the phases column.
   *
   * @return array<int, array<string, mixed>>
   */
  private static function objectList(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }

    return array_values(array_filter($value, 'is_array'));
  }
}

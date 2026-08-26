<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Interfaces\Service;

use Pixiekat\LuminaUiBundle\Enum\MatchingType;
use Pixiekat\LuminaUiBundle\Exception\TrialMatchingUnavailableException;
use Pixiekat\LuminaUiBundle\ReadModel\TrialMatch;

/**
 * TrialMatcherInterface
 * =====================
 *
 * The one and only doorway into EXACT's *matching engine* — the HTTP API at
 * /exact/trials/, as distinct from TrialsManagerInterface which reads the trials
 * *database* over DBAL.
 *
 * ── Why these are two interfaces and not one ────────────────────────────────
 * TrialsManagerInterface's own docblock leaves room "to swap the SQL
 * implementation for EXACT's HTTP API later without touching a caller", which
 * makes merging them look tempting. It would be wrong, because the two answer
 * questions that differ in more than transport:
 *
 *   TrialsManager    "what is trial 44842?"      — patient-independent, cacheable
 *                                                  for as long as the import is,
 *                                                  and available offline in a
 *                                                  database we control.
 *   TrialMatcher     "which trials suit person   — patient-relative, computed per
 *                     107, and how well?"          request, stale the moment the
 *                                                  ctomop record changes, and
 *                                                  reachable only over the network.
 *
 * They also fail differently, which is the practical consequence: a dead
 * exact_db costs us trial titles and degrades to "Trial #44842", while a dead
 * matching API costs us the *ranking* and must degrade to the unranked list.
 * One interface would have to describe both postures at once and would end up
 * describing neither. Keeping the seam here also means the screen still renders
 * from local data when the API is down — which is the whole graceful-degradation
 * story for this feature.
 *
 * ── Read-only, and stateless upstream ───────────────────────────────────────
 * Nothing here writes. EXACT builds the patient profile in memory, matches, and
 * discards it at the end of the request — no patient data is persisted upstream,
 * which is what makes it acceptable to send a ctomop-derived profile over the
 * wire at all. Nothing in this interface should ever acquire a method that
 * causes EXACT to retain something.
 */
interface TrialMatcherInterface {

  /**
   * Matches every trial EXACT knows about against one patient.
   *
   * The patient profile is assembled from ctomop by the implementation — callers
   * pass a person id and never a payload, so there is exactly one place that
   * knows how a ctomop row becomes an EXACT patient profile, and no controller
   * is ever holding a bag of PHI it has to remember not to log.
   *
   * @param int $personId The ctomop `person.person_id`.
   *
   * @param MatchingType[]|null $statuses Which verdicts to ask for. Null means
   *   MatchingType::candidates() — eligible + potential, the policy the
   *   patient-first screen uses. Pass an explicit list to widen or narrow it;
   *   pass every case to get the full corpus back, ranked.
   *
   * @param bool $explain Request the per-criterion breakdown (?explain=true),
   *   which populates TrialMatch::$matchReasons. Off by default because it makes
   *   the response substantially larger and the trial *table* has nowhere to put
   *   it — turn it on for a single-trial detail view, not for a listing.
   *
   * @return array<int, TrialMatch> Matches keyed by trial id, so a caller can do
   *   `$matches[$trial->id] ?? null` rather than scanning. Ordering of the
   *   underlying API response (matchScore descending) is preserved by insertion
   *   order, so iterating the array directly still gives best-first — but pair
   *   it with TrialsManagerInterface::findMany(array_keys($matches)) to render,
   *   and let this array drive the order.
   *
   *   An empty array is a real, successful answer: EXACT considered the corpus
   *   and nothing qualified. It is NOT how failure is reported — see below.
   *
   * @throws TrialMatchingUnavailableException When EXACT could not be consulted
   *   at all. Callers that render a clinical list MUST catch this and fall back
   *   to the unranked list with a visible warning; an empty table would claim
   *   "no trials suit this patient" on the strength of a network error.
   */
  public function matchForPatient(
    int $personId,
    ?array $statuses = null,
    bool $explain = false,
  ): array;

  /**
   * How many trials match, without transferring the trials themselves.
   *
   * Wraps GET /trials/count/. Useful for a summary line ("37 of 367 trials may
   * suit this patient") and for the poller, which wants to know whether anything
   * changed without paying for a full page of JSON on every tick.
   *
   * @param MatchingType[]|null $statuses As matchForPatient().
   *
   * @throws TrialMatchingUnavailableException On the same terms as
   *   matchForPatient(). A count that silently returns 0 when the API is down
   *   would put a confident, wrong number on the screen — worse than no number.
   */
  public function countForPatient(int $personId, ?array $statuses = null): int;
}

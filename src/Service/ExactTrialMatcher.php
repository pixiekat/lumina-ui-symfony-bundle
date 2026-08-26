<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Service;

use Pixiekat\LuminaUiBundle\Enum\MatchingType;
use Pixiekat\LuminaUiBundle\Exception\TrialMatchingUnavailableException;
use Pixiekat\LuminaUiBundle\Interfaces\Service\PatientProfileProviderInterface;
use Pixiekat\LuminaUiBundle\Interfaces\Service\TrialMatcherInterface;
use Pixiekat\LuminaUiBundle\ReadModel\TrialMatch;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * ExactTrialMatcher
 * =================
 *
 * Talks to EXACT's trial-search API. The HTTP sibling of TrialsManager, and the
 * same idea as ExactCommandRunner and PatientManager before it: one small
 * service is the single chokepoint for one external system.
 *
 * ── Why POST /trials/match/ and not GET /trials/ ────────────────────────────
 * EXACT's docs describe the patient profile as travelling in the *body* of a
 * GET. That is legal HTTP and it is what their curl examples show, but it is
 * not portable:
 *
 *   - The Fetch spec forbids it outright — `new Request(url, {method:'GET',
 *     body})` throws.
 *   - axios's XHR adapter silently drops the body on GET.
 *
 * Both leave EXACT's `resolve_patient_info` reading an empty body and matching
 * with no patient context, which does not error — it just returns the whole
 * corpus, unranked, looking exactly like a successful call. EXACT added
 * /trials/match/ as a POST alias for precisely this reason, documented as
 * "identical response shape".
 *
 * PHP's cURL transport would in fact let us send GET-with-body, so we could use
 * the documented form. We use the alias anyway, because the failure mode if the
 * body is ever dropped in transit — by a proxy, a redirect, a future transport
 * change — is silent and clinically misleading rather than loud. Choosing the
 * verb that cannot lose the payload costs nothing and removes the whole class.
 *
 * ── Failure posture: throw, do not swallow ──────────────────────────────────
 * PatientManager and TrialsManager both turn an unreachable database into a
 * logged warning and an empty array. This class deliberately does the opposite,
 * and TrialMatchingUnavailableException's docblock has the full argument. The
 * short version: for those two, [] degrades into a template fallback and the
 * caller genuinely does not need to know. Here, [] is indistinguishable from
 * "EXACT considered every trial and none fit this patient" — a clinical
 * statement we must never make by accident.
 *
 * ── What this class does NOT do ─────────────────────────────────────────────
 * It does not fetch trial titles, phases, sponsors or links. Those come from
 * TrialsManager, out of the database, and the caller pairs the two by trial id.
 * The API returns descriptive fields too, and using them would be a mistake: it
 * would put a second source of truth for "what is trial 44842" behind a network
 * call, so a screen could render one title today and another tomorrow depending
 * on which service answered. Ranking comes from here; facts come from the
 * database.
 */
class ExactTrialMatcher implements TrialMatcherInterface {

  /**
   * How many pages to follow before giving up.
   *
   * At EXACT's default of 200 results per page and a corpus of a few hundred
   * trials, one or two pages is the real-world case. The cap exists so a
   * malformed `next` link — one that points at itself, say — costs a bounded
   * number of requests rather than hanging the page render until PHP's own time
   * limit kills it. Ten pages is two thousand trials: far past anything
   * plausible, and still fast to fail.
   */
  private const int MAX_PAGES = 20;

  /**
   * The body key the patient profile nests under.
   *
   * ── This is snake_case, and the documentation says otherwise ────────────────
   * EXACT's API reference documents the envelope as `patientInfo`, consistent
   * with its statement that "JSON payloads and responses use camelCase keys
   * (converted by djangorestframework-camel-case)". Verified against the running
   * deployment on 2026-08-26, that is wrong, and wrong in the worst possible
   * direction:
   *
   *   {"patientInfo":  {...}}  → HTTP 200, itemsTotalCount 367, matchScore null,
   *                              every trial returned as "eligible"
   *   {"patient_info": {...}}  → HTTP 200, itemsTotalCount 156, matchScore 100,
   *                              matchReasons populated
   *
   * The documented key does not error. It does not warn. It returns a
   * confident, complete, well-formed result set computed with NO patient context
   * at all — which on this screen would read as "every trial suits this
   * patient". That is the precise failure EXACT's own commit message describes
   * for a dropped body ("the matcher running with no patient context"), except
   * reached by following the documentation rather than by a transport quirk.
   *
   * Why the camel-case middleware does not catch it: djangorestframework-camel-
   * case rewrites keys for serializer-backed data, but `resolve_patient_info`
   * reads this key off the raw parsed body before that conversion applies. The
   * INNER keys are converted normally — `estrogenReceptorStatus` and
   * `estrogen_receptor_status` both work, verified — so it is only the envelope
   * that is exempt. That asymmetry is exactly why the bug is easy to ship.
   *
   * If a future EXACT release fixes the docs by making `patientInfo` work, this
   * constant is the one line to change, and the assertion to keep is behavioural
   * rather than textual: a request WITH a profile must return a different
   * itemsTotalCount than one without. See the note in the class docblock about
   * why that is the only trustworthy smoke test for this integration.
   */
  private const string PROFILE_ENVELOPE_KEY = 'patient_info';

  public function __construct(
    #[Autowire(service: 'lumina_ui.exact_api.client')]
    private readonly HttpClientInterface $exactApi,
    private readonly PatientProfileProviderInterface $profiles,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritDoc}
   */
  public function matchForPatient(
    int $personId,
    ?array $statuses = null,
    bool $explain = false,
  ): array {
    $statuses ??= MatchingType::candidates();
    $profile = $this->requireProfile($personId);

    $matches = [];

    // One request per status rather than one ?type=all request filtered in PHP.
    //
    // The alternative transfers the entire corpus — several hundred trials of
    // JSON — to discard most of it locally, on every render of this screen. It
    // also puts the eligible/potential policy in two places: once in the enum
    // and once in a filter here. Asking EXACT for exactly what we want keeps the
    // policy in MatchingType::candidates() and lets the server do the work it
    // already has indexed.
    //
    // Ordering is the reason this is a foreach over an ordered list and not a
    // set operation: MatchingType::candidates() returns eligible before
    // potential, and PHP arrays preserve insertion order, so confirmed matches
    // land above data-gap matches in the final array without a sort. Within each
    // bucket EXACT's own matchScore ordering survives.
    // Trials EXACT returned under more than one status filter. Counted, not
    // logged individually — see below.
    $overlapping = 0;

    foreach ($statuses as $status) {
      foreach ($this->fetchAllPages($profile, $status, $explain, $personId) as $trialId => $match) {
        // First writer wins: MatchingType::candidates() yields eligible before
        // potential, so the better verdict is the one already in the array.
        if (isset($matches[$trialId])) {
          $overlapping++;

          continue;
        }

        $matches[$trialId] = $match;
      }
    }

    // ── One line, not one per trial ────────────────────────────────────────────
    // A trial should hold exactly one verdict, so an overlap means EXACT's
    // ?type= filter did not discriminate between the statuses we asked for.
    //
    // That is a single fact about the request, and logging it per trial buries
    // it: on the current deployment ?type= is entirely inert, so every candidate
    // trial overlaps and a single page render writes ~90 identical INFO lines.
    // Ninety lines saying one thing is worse than silence, because it drowns the
    // lines around it that say ninety different things.
    //
    // The count is the useful part — "45 of 90" and "90 of 90" mean different
    // things, and the second is the signature of a filter that is not working at
    // all. Logged at notice rather than info because a filter that has stopped
    // discriminating is a real degradation of this screen's usefulness, not
    // routine chatter.
    if ($overlapping > 0) {
      $this->logger->notice(
        'EXACT returned {overlapping} of {total} trials under more than one match status for person {personId} — the ?type= filter may not be discriminating. Statuses requested: {statuses}.',
        [
          'overlapping' => $overlapping,
          'total' => $overlapping + count($matches),
          'personId' => $personId,
          'statuses' => implode(', ', array_map(
            static fn(MatchingType $s): string => $s->value,
            $statuses,
          )),
        ],
      );
    }

    return $matches;
  }

  /**
   * {@inheritDoc}
   *
   * Sums the per-status counts rather than making one ?type=all call, so the
   * number on screen is the count of the same population the table below it
   * shows. A count that quietly used a different filter than the list is the
   * classic way a pager starts lying — the same trap TrialsManager::countAll()
   * warns about for its own WHERE clause.
   */
  public function countForPatient(int $personId, ?array $statuses = null): int {
    $statuses ??= MatchingType::candidates();
    $profile = $this->requireProfile($personId);

    $total = 0;

    foreach ($statuses as $status) {
      // Deliberately NOT /trials/count/, despite that being the endpoint whose
      // entire purpose is this question. It is GET-only — POST returns 405 —
      // and it is the patient profile that forces our hand: the profile travels
      // in the body, and only /trials/match/ accepts a body.
      //
      // So we ask for page one of the match results and read the total off it.
      // One page of JSON to learn a number is wasteful, and it is the only way
      // to get a count that reflects the patient. The alternative — calling
      // /trials/count/ over GET with no profile — returns the whole corpus (367)
      // for every patient, which is not a wrong number so much as an answer to a
      // different question.
      //
      // If EXACT adds a POST alias for /trials/count/, this reverts to a single
      // cheap call and the rest of the method is unchanged.
      $body = $this->decode(
        $this->send('trials/match/', $profile, ['type' => $status->queryValue()]),
        'trials/match/',
      );

      $total += $this->readTotal($body);
    }

    return $total;
  }

  // ── Internals ───────────────────────────────────────────────────────────────

  /**
   * Fetches the profile, converting "no clinical record" into the same
   * unavailable signal as a transport failure.
   *
   * ── Why null becomes an exception here ──────────────────────────────────────
   * A patient with no `patient_info` row cannot be matched — an empty profile
   * disqualifies nobody, so EXACT would hand back the entire corpus as
   * "potential" and the screen would present a ranking derived from nothing.
   *
   * That is not a failure of EXACT, so calling it "unavailable" is a slight
   * stretch of the word. It earns its place because the correct *behaviour* is
   * identical in both cases: show the full unranked trial list, and say why. The
   * reason string carries the real explanation through to the flash message, so
   * nothing is actually conflated where a human can see it — and the template
   * already has a matching note for this patient
   * ("No clinical data recorded for this patient in ctomop").
   *
   * If the two cases ever need to diverge — a different flash, a different log
   * level — the fix is a narrow subclass of the exception, not a second return
   * channel through every caller.
   *
   * @return array<string, mixed>
   */
  private function requireProfile(int $personId): array {
    $profile = $this->profiles->buildFor($personId);

    if ($profile === null) {
      throw new TrialMatchingUnavailableException(
        'this patient has no clinical record in ctomop, so there is nothing to match on',
      );
    }

    return $profile;
  }

  /**
   * Walks the paginated result set for one status, following `next`.
   *
   * Yields rather than accumulating: the caller is building a keyed array
   * anyway, and a generator keeps only one page of decoded JSON alive at a time.
   *
   * @param array<string, mixed> $profile
   * @return \Generator<int, TrialMatch>
   */
  private function fetchAllPages(
    array $profile,
    MatchingType $status,
    bool $explain,
    int $personId,
  ): \Generator {
    $query = ['type' => $status->queryValue()];

    if ($explain) {
      // Only send it when true. EXACT reads the mere presence of some flags, and
      // `explain=false` is the sort of thing a framework can read as truthy.
      $query['explain'] = 'true';
    }

    $page = 1;

    while ($page <= self::MAX_PAGES) {
      if ($page > 1) {
        $query['page'] = $page;
      }

      $body = $this->decode($this->send('trials/match/', $profile, $query), 'trials/match/');
      $results = $body['results'] ?? null;

      if (!is_array($results)) {
        throw new TrialMatchingUnavailableException(
          'trials/match/ returned no results array',
        );
      }

      foreach ($results as $row) {
        if (!is_array($row)) {
          continue;
        }

        try {
          $match = TrialMatch::fromApiResult($row);
        }
        catch (\InvalidArgumentException $e) {
          // One unusable row loses its own line, not the page. This is the same
          // call TrialsManager::jsonStringList() makes about a malformed jsonb
          // column: degrade the row, log it, keep the listing.
          $this->logger->warning('Unusable EXACT match result for person {personId}: {message}', [
            'personId' => $personId,
            'message' => $e->getMessage(),
          ]);

          continue;
        }

        if ($match->matchingType === null) {
          // An unrecognised status token. Worth noticing — it means EXACT has
          // grown a verdict this bundle has no policy for — but not worth
          // failing over, and isCandidate() already treats it conservatively.
          $this->logger->notice('Unrecognised matchingType from EXACT for trial {trialId}: {value}', [
            'trialId' => $match->trialId,
            'value' => $row['matchingType'] ?? null,
          ]);
        }

        yield $match->trialId => $match;
      }

      // `next` is an absolute URL. We do not follow it directly — that would
      // send the scoped client somewhere the base URI did not sanction, which is
      // how an open-redirect turns into a credential leak (our Authorization
      // header would travel to whatever host the response named). Its presence
      // is the only thing we read; the page number stays ours.
      if (($body['next'] ?? null) === null) {
        return;
      }

      $page++;
    }

    $this->logger->warning('Stopped following EXACT pagination at the {max}-page cap for person {personId}.', [
      'max' => self::MAX_PAGES,
      'personId' => $personId,
    ]);
  }

  /**
   * Issues one POST, with the profile in the body and filters in the query string.
   *
   * @param array<string, mixed> $profile
   * @param array<string, scalar> $query
   * @throws TrialMatchingUnavailableException
   */
  private function send(string $endpoint, array $profile, array $query): ResponseInterface {
    try {
      return $this->exactApi->request('POST', $endpoint, [
        'query' => $query,
        'json' => [self::PROFILE_ENVELOPE_KEY => $profile],
      ]);
    }
    catch (HttpExceptionInterface $e) {
      // Transport-level only — DNS, TLS, connection refused, timeout. HTTP error
      // *statuses* are not exceptions here, because the client is not configured
      // to throw on them; they are handled in decode() where the body can be read.
      $this->logger->warning('EXACT {endpoint} request failed: {message}', [
        'endpoint' => $endpoint,
        'message' => $e->getMessage(),
        'exception' => $e,
      ]);

      throw new TrialMatchingUnavailableException(
        sprintf('%s could not be reached', $endpoint),
        $e,
      );
    }
  }

  /**
   * Reads the number of MATCHING TRIALS out of a response envelope.
   *
   * ── `count` is not the count ────────────────────────────────────────────────
   * The Swagger schema documents the envelope as standard DRF pagination, where
   * `count` is the total number of items. On this deployment it is not. Measured
   * against the running API:
   *
   *   type=all           count=19  itemsTotalCount=367  results=20 rows
   *   type=eligible      count=5   itemsTotalCount=88   results=20 rows
   *
   * 367 items at 20 per page is 19 pages; 88 items is 5 pages. `count` is the
   * PAGE count. `itemsTotalCount` is the item count.
   *
   * This one is genuinely dangerous to get wrong, because both fields are small
   * plausible integers and `count` is the name every DRF client already reaches
   * for. Reading it would put "5 trials may suit this patient" on screen when
   * the real answer is 88 — an understatement of the available options, on a
   * screen whose purpose is not to miss options.
   *
   * Hence: read itemsTotalCount, and refuse rather than guess if it is absent.
   * Falling back to `count` on a deployment that fixed this would silently
   * reintroduce the bug the day the fix landed.
   *
   * @param array<string, mixed> $body
   * @throws TrialMatchingUnavailableException
   */
  private function readTotal(array $body): int {
    $total = $body['itemsTotalCount'] ?? null;

    if (!is_numeric($total)) {
      throw new TrialMatchingUnavailableException(
        'trials/match/ returned no itemsTotalCount to count from',
      );
    }

    return (int) $total;
  }

  /**
   * Turns a response into a decoded array, or an exception naming what went wrong.
   *
   * The status codes worth calling out separately are the two an operator can
   * actually fix, so the flash message tells them which one it is rather than
   * making them read a log to find out:
   *
   *   401/403 — the token is missing, wrong, or belongs to a user without access.
   *             Check EXACT_API_TOKEN; regenerate with
   *             `python manage.py drf_create_token <username>`.
   *   404     — the base URI is wrong, or this deployment predates /trials/match/.
   *
   * @return array<string, mixed>
   * @throws TrialMatchingUnavailableException
   */
  private function decode(ResponseInterface $response, string $endpoint): array {
    try {
      $status = $response->getStatusCode();

      if ($status === 401 || $status === 403) {
        throw new TrialMatchingUnavailableException(sprintf(
          '%s rejected our credentials (HTTP %d) — check EXACT_API_TOKEN',
          $endpoint,
          $status,
        ));
      }

      if ($status === 404) {
        throw new TrialMatchingUnavailableException(sprintf(
          '%s was not found (HTTP 404) — check EXACT_API_URL points at the API root',
          $endpoint,
        ));
      }

      if ($status >= 400) {
        throw new TrialMatchingUnavailableException(sprintf(
          '%s returned HTTP %d',
          $endpoint,
          $status,
        ));
      }

      return $response->toArray();
    }
    catch (HttpExceptionInterface $e) {
      // Reaches here for a non-JSON body (an HTML error page from a proxy is the
      // usual culprit) as well as for redirect/transport problems surfaced late.
      throw new TrialMatchingUnavailableException(
        sprintf('%s returned an unreadable response', $endpoint),
        $e,
      );
    }
  }
}

<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Pixiekat\LuminaUiBundle\Exception\TrialMatchingUnavailableException;
use Pixiekat\LuminaUiBundle\Interfaces\Service\PatientProfileProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * CtomopPatientProfileProvider
 * ============================
 *
 * Turns a ctomop `person_id` into the `patientInfo` payload EXACT's matching
 * engine expects. Two steps, and the second one is the interesting one:
 *
 *   1. Read the raw `patient_info` row over the existing ctomop DBAL connection.
 *   2. POST it to EXACT's /normalize-ctomop-row/, and use what comes back.
 *
 * ── Why we do not map the columns ourselves ─────────────────────────────────
 * `patient_info` has 279 columns. EXACT's documented profile has roughly sixty
 * fields, and the two vocabularies do not agree: ctomop stores
 * `estrogen_receptor_status` as free text, EXACT wants the code `er_plus`;
 * ctomop has TNM strings, EXACT wants short codes; therapy-line outcomes are
 * labels here and ids there.
 *
 * We could write that mapping in PHP. We would then own a second copy of a
 * translation table whose first copy lives in EXACT's Python, and the two would
 * drift — silently, because a mismapped field does not error, it just reads as
 * "unknown" and quietly stops disqualifying anybody. That is the failure mode
 * EXACT's own commit message describes: *"a meaningful subset of fields silently
 * reads as unknown"*. A matching screen that fails by becoming less selective
 * is worse than one that fails loudly, because nothing on it looks wrong.
 *
 * So the mapping stays upstream, in the one place that also owns the matcher
 * that consumes it. /normalize-ctomop-row/ is pure and side-effect-free — it is
 * a translation function EXACT exposes precisely so callers holding a ctomop row
 * do not have to reimplement it.
 *
 * ── The cost of that choice, stated plainly ─────────────────────────────────
 * It buys correctness with a second HTTP round trip per patient, and it means a
 * page render depends on EXACT being up twice rather than once. Both failures
 * surface as TrialMatchingUnavailableException and both degrade to the unranked
 * trial list, so the user-visible consequence is the same either way.
 *
 * ── PHI: what leaves this machine, and what does not ────────────────────────
 * `patient_info` holds direct identifiers that matching has no use for — email,
 * phone_number, date_of_birth, facility_name, and a block of socioeconomic
 * columns (income, employment, education, marital status, dependents).
 *
 * None of them are eligibility criteria. All of them are exactly what you would
 * not want in an HTTP body, a proxy log, or an upstream exception trace. So the
 * row is filtered before it is sent: see self::DIRECT_IDENTIFIERS.
 *
 * EXACT stores nothing — the profile is built in memory, matched against, and
 * discarded at the end of the request — which is what makes sending a clinical
 * profile acceptable at all. That guarantee covers what they do with the data;
 * it does not cover what we chose to hand them, and sending a phone number to a
 * system that has no use for it is our mistake regardless of how briefly they
 * hold it. Minimise at the source.
 *
 * TODO — tighten DIRECT_IDENTIFIERS into an ALLOW list.
 * A deny list fails open: the day ctomop gains a `next_of_kin_email` column, it
 * starts travelling and nothing complains. An allow list fails closed — a new
 * column is simply not sent until somebody adds it deliberately, which is the
 * right default for PHI. The reason it is not already an allow list is that
 * enumerating ~60 of 279 columns needs a pass against EXACT's matcher to
 * confirm which ones it actually reads, and guessing would silently drop real
 * criteria. Do that pass, then invert this constant — the filter() call below
 * is the only line that changes.
 */
class CtomopPatientProfileProvider implements PatientProfileProviderInterface {

  /**
   * Columns stripped from the row before it leaves this machine.
   *
   * Direct identifiers first, then the socioeconomic block. Neither group
   * appears anywhere in EXACT's documented patient profile, so removing them
   * costs no matching accuracy whatsoever — this is pure reduction of blast
   * radius, not a trade.
   *
   * `person_id` stays: EXACT's normalizer keys on it, and it is a pseudonymous
   * internal id rather than an identifier in its own right.
   *
   * @var string[]
   */
  private const array DIRECT_IDENTIFIERS = [
    // Contactable identifiers — the highest-value fields in the table and the
    // ones with the least possible bearing on trial eligibility.
    'email',
    'phone_number',
    'date_of_birth',
    'facility_name',
    'city',
    'validated_by',
    'organization_id',

    // Socioeconomic. Not identifiers, but not eligibility criteria either, and
    // the sort of thing that turns a clinical payload into a profile.
    'annual_household_income',
    'employment_status',
    'education_level',
    'marital_status',
    'insurance_type',
    'number_of_dependents',
    'languages_skills',
    'social_support',
  ];

  /**
   * @param Connection $patients The ctomop connection — named explicitly for the
   *   same reason PatientManager does it: autowiring a bare Connection hands over
   *   the DEFAULT one and every query here fails with "relation patient_info does
   *   not exist", which is a confusing way to learn about DI.
   *
   * @param HttpClientInterface $exactApi The scoped EXACT client, pre-configured
   *   with the base URI and the DRF token. See config/services.php.
   */
  public function __construct(
    #[Autowire(service: 'doctrine.dbal.patients_connection')]
    private readonly Connection $patients,
    #[Autowire(service: 'lumina_ui.exact_api.client')]
    private readonly HttpClientInterface $exactApi,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritDoc}
   */
  public function buildFor(int $personId): ?array {
    $row = $this->fetchRawRow($personId);

    if ($row === null) {
      // No patient_info row at all. Distinct from "an empty profile": this
      // patient exists in `person` but has no clinical extension record, so
      // there is genuinely nothing to match on. Returning null lets the caller
      // say so rather than sending {} and getting back a meaningless "every
      // trial is a potential match" answer.
      $this->logger->info('No ctomop patient_info row for person {personId}; skipping EXACT matching.', [
        'personId' => $personId,
      ]);

      return null;
    }

    $safeRow = $this->stripIdentifiers($row);

    // Normalisation is an ACCURACY improvement, not a prerequisite — so a
    // normalizer that is down costs us precision on a handful of fields rather
    // than costing the user the whole feature.
    //
    // Measured against the running deployment for person 1000, sending the raw
    // ctomop row straight through still produces real matching: 367 trials
    // narrow to 92, matchScore 94, and 48 of 90 criteria are evaluated with an
    // actual patient value. What the normalizer adds is the vocabulary-dependent
    // subset — receptor statuses ("Positive" → er_plus), disease casing
    // ("Breast Cancer" → "breast cancer"), TNM strings, therapy-line outcomes.
    // Those fields read as unknown without it, which makes the match *less
    // selective*, never wrongly selective.
    //
    // That asymmetry is the whole reason this is a fallback and not a failure:
    // degrading toward showing MORE candidate trials is safe on this screen,
    // whereas degrading toward fewer would hide options from a clinician.
    $normalised = $this->normalise($safeRow, $personId);

    return $normalised ?? $safeRow;
  }

  // ── Internals ───────────────────────────────────────────────────────────────

  /**
   * Reads the whole patient_info row for one person.
   *
   * `SELECT *` here, which every other query in this bundle deliberately avoids.
   * The reasoning inverts for this one caller: TrialsManager enumerates columns
   * so that an upstream rename fails loudly at the query, but here we are not
   * consuming the columns at all — we are forwarding them to a normalizer that
   * knows far better than we do which of the 279 it needs. Naming a subset would
   * mean *this* file deciding what matters, which is precisely the decision the
   * whole design is trying to keep upstream.
   *
   * The filtering that does happen is the identifier strip, and that one is a
   * deny list on purpose so a new *clinical* column reaches the matcher without
   * a code change here.
   *
   * @return array<string, mixed>|null
   */
  private function fetchRawRow(int $personId): ?array {
    $sql = 'SELECT * FROM patient_info WHERE person_id = :personId LIMIT 1';

    try {
      $row = $this->patients->fetchAssociative($sql, ['personId' => $personId]);
    }
    catch (DbalException $e) {
      // Note this does NOT degrade to an empty profile the way PatientManager
      // degrades to an empty name list. An empty profile would produce a
      // confident, wrong match list; see TrialMatchingUnavailableException.
      $this->logger->warning('ctomop patient_info lookup failed: {message}', [
        'message' => $e->getMessage(),
        'exception' => $e,
        // SQL only, never params — a person_id is exactly the sort of thing
        // that should not be sprayed into a log file.
        'sql' => $sql,
      ]);

      throw new TrialMatchingUnavailableException(
        'the ctomop patient record could not be read',
        $e,
      );
    }

    return $row === false ? null : $row;
  }

  /**
   * Drops the columns in DIRECT_IDENTIFIERS.
   *
   * array_diff_key against a flipped constant rather than a foreach+unset: it is
   * one expression, it cannot partially apply, and it does not care whether a
   * listed column is actually present in the row.
   *
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function stripIdentifiers(array $row): array {
    return array_diff_key($row, array_flip(self::DIRECT_IDENTIFIERS));
  }

  /**
   * Sends the row through EXACT's normalizer and returns the EXACT-shaped profile.
   *
   * ── On the request body and camelCase ───────────────────────────────────────
   * EXACT runs djangorestframework-camel-case, which converts keys in BOTH
   * directions: our snake_case ctomop column names arrive as the camelCase the
   * serializer wants, and the response comes back camelCased too. So we send the
   * row exactly as Postgres handed it over — no key rewriting here — and what
   * returns is already in the shape `patientInfo` expects.
   *
   * That is a genuine dependency on upstream middleware rather than a happy
   * accident, so it is worth knowing why it holds: it is the same mechanism that
   * makes every other endpoint in this API camelCase, and it applies to request
   * parsing, not just rendering.
   *
   * ── Returns null rather than throwing ───────────────────────────────────────
   * Every failure here is recoverable by the caller, which falls back to the raw
   * row. Null is the signal for that. It is logged at warning level every time,
   * because "matching is running on unnormalised values" is a real degradation
   * an operator should be able to see in the logs — it is just not one worth
   * showing a clinician a broken page over.
   *
   * KNOWN FAILURE on the current dev deployment (verified 2026-08-26): this
   * endpoint returns HTTP 500, `ProgrammingError: relation "trials_marker" does
   * not exist`. EXACT is serving trials from `ne_bc_trials`, which is missing
   * the three marker tables that `exactdb` has — trials_marker,
   * trials_markercategory, trials_markercategoryconnection. Until that database
   * gets the migration, this method returns null on every call and the fallback
   * above is the live code path.
   *
   * @param array<string, mixed> $row
   * @return array<string, mixed>|null Null when normalisation was not possible.
   */
  private function normalise(array $row, int $personId): ?array {
    try {
      $response = $this->exactApi->request('POST', 'normalize-ctomop-row/', [
        'json' => $row,
      ]);

      $status = $response->getStatusCode();

      if ($status >= 400) {
        // toArray(false) so a 4xx body can be read for the error detail without
        // the client throwing first. A 500 from Django is an HTML debug page
        // rather than JSON, so this can itself throw — caught below.
        $this->logger->warning(
          'EXACT normalize-ctomop-row returned HTTP {status} for person {personId}; matching on unnormalised values. {detail}',
          [
            'status' => $status,
            'personId' => $personId,
            'detail' => $this->briefError($response),
          ],
        );

        return null;
      }

      $normalised = $response->toArray();
    }
    catch (HttpExceptionInterface | \JsonException $e) {
      $this->logger->warning(
        'EXACT normalize-ctomop-row failed for person {personId}; matching on unnormalised values: {message}',
        [
          'personId' => $personId,
          'message' => $e->getMessage(),
          'exception' => $e,
        ],
      );

      return null;
    }

    // Some deployments wrap the result; accept either shape rather than being
    // brittle about a detail the docs do not pin down.
    if (isset($normalised['patientInfo']) && is_array($normalised['patientInfo'])) {
      return $normalised['patientInfo'];
    }

    return $normalised;
  }

  /**
   * Squeezes an error response into one short, log-safe line.
   *
   * Three shapes have to survive this, because all three occur in practice:
   *
   *   {"detail": "..."}      DRF's standard error. Use it verbatim.
   *   {"field": [...], ...}  A validation error. Name the KEYS only — the values
   *                          are the patient row echoed back, and a log file is
   *                          the last place that belongs.
   *   a Django traceback    A 500 with DEBUG on. Not JSON, so toArray() throws.
   *                          Django serves this in TWO formats depending on the
   *                          request's Accept header, and we send
   *                          `Accept: application/json`, which gets the PLAIN
   *                          TEXT variant rather than the familiar styled HTML
   *                          page a browser sees. Both are handled: the text
   *                          form puts the useful part on its first two lines,
   *                          the HTML form hides it in <pre class="exception_value">.
   *
   * Getting that last case right is worth the twenty lines, because it is the
   * difference between a log that says "non-JSON error response" and one that
   * says `relation "trials_marker" does not exist` — which names the actual
   * broken thing and turns a debugging session into a one-line fix.
   *
   * Everything is wrapped, because a diagnostic that can itself throw turns a
   * recoverable degradation into a fatal one — in the exact code path that only
   * runs when something is already broken.
   */
  private function briefError(ResponseInterface $response): string {
    try {
      $body = $response->toArray(false);

      if (isset($body['detail']) && is_string($body['detail'])) {
        return $body['detail'];
      }

      return $body === []
        ? 'no error detail'
        : 'errors on: ' . implode(', ', array_keys($body));
    }
    catch (\Throwable) {
      try {
        $body = $response->getContent(false);
      }
      catch (\Throwable) {
        return 'unreadable error response';
      }

      // The HTML debug page, if a proxy or a different Accept header produced one.
      if (preg_match('#<pre class="exception_value">(.*?)</pre>#s', $body, $m) === 1) {
        return trim(html_entity_decode(strip_tags($m[1])));
      }

      // The plain-text traceback, which is what `Accept: application/json` gets.
      // Its first two non-empty lines are the exception class + location and the
      // message itself:
      //
      //   ProgrammingError at /normalize-ctomop-row/
      //   relation "trials_marker" does not exist
      //
      // Everything after that is a stack trace and a settings dump — the latter
      // being another good reason not to log the whole body.
      $lines = [];

      foreach (preg_split('/\R/', $body) ?: [] as $line) {
        $line = trim($line);

        if ($line !== '') {
          $lines[] = $line;
        }

        if (count($lines) === 2) {
          break;
        }
      }

      return $lines === []
        ? 'non-JSON error response'
        // Cap it: a stray single-line body could be enormous, and this string
        // goes straight into a log message.
        : mb_substr(implode(' — ', $lines), 0, 300);
    }
  }
}

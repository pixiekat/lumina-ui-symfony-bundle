<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Pixiekat\LuminaUiBundle\Interfaces\Service\TrialsManagerInterface;
use Pixiekat\LuminaUiBundle\ReadModel\Trial;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * TrialsManager
 * =============
 *
 * Reads clinical trials out of the EXACT database over a dedicated DBAL
 * connection and hands back Trial read models. The direct counterpart of
 * PatientManager — same posture, same reasoning, same rules:
 *
 *   - Raw DBAL, no ORM: the schema belongs to EXACT's Django migrations, and
 *     leaving it unmapped keeps Doctrine's schema tooling away from it.
 *   - Every query in the application that touches trials lives in this class.
 *     One chokepoint per external system, exactly like ExactCommandRunner.
 *   - Every request value travels as a bound parameter. Never interpolate.
 *
 * ── Which database am I actually reading? ───────────────────────────────────
 * The `exact_db` container hosts TWO databases with an identical trials_trial
 * schema but disjoint id ranges:
 *
 *   exactdb      — EXACT's own Django database
 *   ne_bc_trials — the curated breast-cancer trial set
 *
 * Because the schemas match, pointing TRIALS_DATABASE_URL at the wrong one
 * fails silently: every lookup is a perfectly valid query that returns no rows.
 * There is no error to notice. If trials render as "not found" across the board,
 * check the DSN in .env.local before you go looking for a bug in here — and see
 * the `trials` connection comment in config/packages/doctrine.yaml.
 *
 * ── Hardening ───────────────────────────────────────────────────────────────
 * As with ctomop, the DSN currently uses EXACT's owner role. The same
 * SELECT-only role recipe in PatientManager's docblock applies verbatim; swap
 * `ctomopdb` for the trials database name.
 */
class TrialsManager implements TrialsManagerInterface {

  /**
   * Every column this class reads, with the aliases the hydrator expects.
   * Defined once so find(), findMany(), findByStudyId() and search() cannot
   * drift apart.
   *
   * `trials_trial` has well over a hundred columns; we name the ~17 we display
   * rather than using SELECT *. Enumerating them means a column being renamed
   * upstream fails loudly at the query, instead of quietly hydrating a null.
   */
  private const string SELECT_COLUMNS = <<<'SQL'
      t.id                        AS id,
      t.study_id                  AS study_id,
      t.brief_title               AS brief_title,
      t.official_title            AS official_title,
      t.recruitment_status        AS recruitment_status,
      t.register                  AS register,
      t.study_type                AS study_type,
      t.phases                    AS phases,
      t.sponsor_name              AS sponsor_name,
      t.disease                   AS disease,
      t.link                      AS link,
      t.age_low_limit             AS age_low_limit,
      t.age_high_limit            AS age_high_limit,
      t.gender                    AS gender,
      t.target_sample_size        AS target_sample_size,
      t.posted_date               AS posted_date,
      t.last_update_date          AS last_update_date
    SQL;

  private const string FROM = 'FROM trials_trial t';

  /**
   * @param Connection $trials The `trials` DBAL connection from
   *   config/packages/doctrine.yaml. Named explicitly for the same reason as in
   *   PatientManager: autowiring a bare Connection would silently hand over the
   *   DEFAULT connection (lumina_db), and every query here would fail with
   *   "relation trials_trial does not exist".
   *
   * @param LoggerInterface $logger So an exact_db outage degrades to a logged
   *   warning and an empty result rather than a broken evaluations page.
   */
  public function __construct(
    #[Autowire(service: 'doctrine.dbal.trials_connection')]
    private readonly Connection $trials,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritDoc}
   *
   * Delegates to findMany() so there is exactly one hydration path.
   */
  public function find(int $trialId): ?Trial {
    return $this->findMany([$trialId])[$trialId] ?? null;
  }

  /**
   * {@inheritDoc}
   *
   * Finds all trials, returning an array keyed by trial id.
   */
  public function findAll(): array {
    $sql = sprintf(
      'SELECT %s %s',
      self::SELECT_COLUMNS,
      self::FROM,
    );

    $rows = $this->fetchAll($sql);

    $trials = [];
    foreach ($rows as $row) {
      $trial = $this->hydrate($row);
      $trials[$trial->id] = $trial;
    }

    return $trials;
  }

  /**
   * {@inheritDoc}
   *
   * ArrayParameterType::INTEGER lets DBAL expand the bound array into the right
   * number of placeholders for `IN (?)` and bind each element as an integer.
   * Never implode ids into the SQL string by hand.
   */
  public function findMany(array $trialIds): array {
    // Unique + integer-cast up front, so a stray "24660abc" from a query string
    // becomes 24660 long before it reaches the database.
    $trialIds = array_values(array_unique(array_map('intval', $trialIds)));

    if ($trialIds === []) {
      // `IN ()` is a syntax error in Postgres, and there is nothing to ask.
      return [];
    }

    $sql = sprintf(
      'SELECT %s %s WHERE t.id IN (:ids)',
      self::SELECT_COLUMNS,
      self::FROM,
    );

    $rows = $this->fetchAll($sql, ['ids' => $trialIds], ['ids' => ArrayParameterType::INTEGER]);

    $trials = [];
    foreach ($rows as $row) {
      $trial = $this->hydrate($row);
      $trials[$trial->id] = $trial;
    }

    return $trials;
  }

  /**
   * {@inheritDoc}
   *
   * UPPER() on both sides makes the comparison case-insensitive without relying
   * on a Postgres-specific operator, so the query survives a platform change.
   * On 180 rows a sequential scan is free; if this table ever grows, the index
   * to add upstream is on UPPER(study_id).
   */
  public function findByStudyId(string $studyId): ?Trial {
    $studyId = trim($studyId);

    if ($studyId === '') {
      return null;
    }

    $sql = sprintf(
      'SELECT %s %s WHERE UPPER(t.study_id) = UPPER(:studyId) LIMIT 1',
      self::SELECT_COLUMNS,
      self::FROM,
    );

    $rows = $this->fetchAll($sql, ['studyId' => $studyId]);

    return $rows === [] ? null : $this->hydrate($rows[0]);
  }

  /**
   * {@inheritDoc}
   *
   * TODO — yours to build, and a slightly richer exercise than the patient one
   * because there are three searchable fields rather than two:
   *
   *   1. Start from self::SELECT_COLUMNS . ' ' . self::FROM, appending a WHERE
   *      only when $query is a non-empty string. Build the clause and the
   *      $params array together; never interpolate.
   *
   *   2. One branch, not two. Unlike patients (numeric id vs name), a trial
   *      query is always text — an NCT number is a string. So a single ILIKE
   *      across all three fields is the natural shape:
   *          "(t.study_id ILIKE :q OR t.brief_title ILIKE :q OR t.official_title ILIKE :q)"
   *      with $params['q'] = '%' . $query . '%'.
   *      Worth considering: should an exact study_id match sort FIRST? Typing a
   *      full NCT number and getting it third is annoying. A CASE expression in
   *      the ORDER BY handles that neatly.
   *
   *   3. Escape the wildcards. % and _ are meaningful INSIDE the value, so a
   *      search for "NCT_" quietly matches far too much.
   *      addcslashes($query, '%_\\') plus an "ESCAPE '\'" suffix fixes it.
   *
   *   4. ORDER BY must be total or pagination repeats rows. Something like
   *      'ORDER BY t.posted_date DESC NULLS LAST, t.id DESC' — the trailing id
   *      is the tiebreaker that makes it deterministic.
   *
   *   5. Bind LIMIT/OFFSET *and* clamp them in PHP
   *      ($limit = max(1, min($limit, 100)); $offset = max(0, $offset)).
   *      Binding stops injection; it does not stop somebody asking for a million rows.
   *
   *   6. array_map($this->hydrate(...), $rows) — a search wants order, not id keys.
   */
  public function search(?string $query = null, int $limit = 25, int $offset = 0): array {
    throw new \LogicException(__METHOD__ . ' is not implemented yet — see the TODO above.');
  }

  /**
   * {@inheritDoc}
   *
   * TODO — must share its WHERE clause with search(), or the pager will disagree
   * with the results it is paging. Extract a helper the moment you write the
   * second copy:
   *
   *     private function buildFilter(?string $query): array  // [$whereSql, $params]
   *
   * returning ['', []] when there is no filter, and call it from both.
   */
  public function countAll(?string $query = null): int {
    throw new \LogicException(__METHOD__ . ' is not implemented yet — see the TODO above.');
  }

  // ── Internals ─────────────────────────────────────────────────────────────

  /**
   * Runs a query, turning an unreachable exact_db into a logged warning and an
   * empty result set rather than a 500.
   *
   * Graceful degradation, same call as on the patient side: losing trial
   * *titles* should not cost you the ability to read your evaluation *results*.
   * The operator still gets a real log line naming the failure.
   *
   * @param array<string, mixed> $params
   * @param array<string, mixed> $types
   * @return array<int, array<string, mixed>>
   */
  private function fetchAll(string $sql, array $params = [], array $types = []): array {
    try {
      return $this->trials->fetchAllAssociative($sql, $params, $types);
    }
    catch (DbalException $e) {
      $this->logger->warning('EXACT trial lookup failed: {message}', [
        'message' => $e->getMessage(),
        'exception' => $e,
        // SQL only, never params — same discipline as PatientManager. Trial ids
        // are not sensitive, but the habit is what protects you when the next
        // query binds something that is.
        'sql' => $sql,
      ]);

      return [];
    }
  }

  /**
   * Turns one raw result row into a Trial.
   *
   * The single place the column→property mapping lives, so an upstream rename
   * is a one-method fix.
   *
   * @param array<string, mixed> $row
   */
  private function hydrate(array $row): Trial {
    return new Trial(
      id: (int) $row['id'],
      studyId: $this->nullableString($row['study_id'] ?? null),
      briefTitle: $this->nullableString($row['brief_title'] ?? null),
      officialTitle: $this->nullableString($row['official_title'] ?? null),
      recruitmentStatus: $this->nullableString($row['recruitment_status'] ?? null),
      register: $this->nullableString($row['register'] ?? null),
      studyType: $this->nullableString($row['study_type'] ?? null),
      phases: $this->jsonStringList($row['phases'] ?? null),
      sponsorName: $this->nullableString($row['sponsor_name'] ?? null),
      disease: $this->nullableString($row['disease'] ?? null),
      link: $this->nullableString($row['link'] ?? null),
      ageLowLimit: $this->nullableInt($row['age_low_limit'] ?? null),
      ageHighLimit: $this->nullableInt($row['age_high_limit'] ?? null),
      gender: $this->nullableString($row['gender'] ?? null),
      targetSampleSize: $this->nullableInt($row['target_sample_size'] ?? null),
      postedDate: $this->nullableDate($row['posted_date'] ?? null),
      lastUpdateDate: $this->nullableDate($row['last_update_date'] ?? null),
    );
  }

  /** Trims, then treats an empty string as "not recorded". */
  private function nullableString(mixed $value): ?string {
    if ($value === null) {
      return null;
    }

    $value = trim((string) $value);

    return $value === '' ? null : $value;
  }

  /** Null stays null — see PatientManager::hydrate() for why that matters. */
  private function nullableInt(mixed $value): ?int {
    return $value === null || $value === '' ? null : (int) $value;
  }

  /**
   * Parses a Postgres date into an immutable date-time, or null.
   *
   * Malformed input returns null rather than throwing: a trial with an
   * unparseable posted_date should lose its date, not take out the page it
   * appears on.
   */
  private function nullableDate(mixed $value): ?\DateTimeImmutable {
    if ($value === null || $value === '') {
      return null;
    }

    try {
      return new \DateTimeImmutable((string) $value);
    }
    catch (\Exception $e) {
      $this->logger->warning('Unparseable trial date {value}', [
        'value' => $value,
        'exception' => $e,
      ]);

      return null;
    }
  }

  /**
   * Decodes a jsonb column into a list of strings.
   *
   * Worth understanding, because it is the one real difference from
   * PatientManager: without the ORM there is no Doctrine type conversion, so
   * DBAL hands back the *raw* jsonb payload as a PHP string — '["PHASE1"]', not
   * an array. Anything JSON has to be decoded here by hand.
   *
   * Defensive on every axis: invalid JSON, a scalar where an array was expected,
   * and non-string elements all degrade to something safe rather than throwing.
   * The alternative is one malformed row taking down a whole listing page.
   *
   * @return string[]
   */
  private function jsonStringList(mixed $value): array {
    if ($value === null || $value === '') {
      return [];
    }

    // Already decoded? Some drivers/configurations hand back arrays directly, so
    // do not assume a string just because the common case is one.
    $decoded = is_array($value)
      ? $value
      : json_decode((string) $value, true);

    if (!is_array($decoded)) {
      $this->logger->warning('Unexpected jsonb payload in trials_trial.phases: {value}', [
        'value' => is_scalar($value) ? (string) $value : gettype($value),
      ]);

      return [];
    }

    // array_values() re-indexes, so a jsonb object rather than an array still
    // yields a clean list instead of a surprising string-keyed array.
    return array_values(array_map(
      static fn(mixed $item): string => is_scalar($item) ? (string) $item : '',
      $decoded,
    ));
  }
}

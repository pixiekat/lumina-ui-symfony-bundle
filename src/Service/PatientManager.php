<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Pixiekat\LuminaUiBundle\Interfaces\Service\PatientManagerInterface;
use Pixiekat\LuminaUiBundle\ReadModel\Patient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * PatientManager
 * ==============
 *
 * Reads patient records out of the ctomop database over a dedicated DBAL
 * connection, and hands back plain Patient read models.
 *
 * ── Why raw DBAL rather than the ORM ────────────────────────────────────────
 * ctomop's schema belongs to a Django project. Mapping it as Doctrine entities
 * would couple our deployment to theirs, and would put ctomop's tables inside
 * the reach of `doctrine:migrations:diff`. DBAL gives us the good parts of
 * Doctrine — connection management, prepared statements, the profiler timeline,
 * platform-aware quoting — with none of the ownership implications.
 *
 * This is the same idea as ExactCommandRunner in this directory: one small
 * service is the single chokepoint for one external system. Keep every ctomop
 * query inside this class. The moment SQL starts appearing in a controller, the
 * boundary is gone and the next schema change over in ctomop becomes a hunt.
 *
 * ── Sanitisation is NOT optional here ───────────────────────────────────────
 * Every value that comes from a request must travel as a bound parameter, never
 * as string interpolation. `executeQuery($sql, $params)` prepares the statement
 * and sends values separately, so a person_id of `1 OR 1=1` is looked up as a
 * literal string and simply finds nothing. There is no scenario in this class
 * where concatenating user input into $sql is correct.
 *
 * ── Hardening worth doing later ─────────────────────────────────────────────
 * The DSN currently uses ctomop's owner role, which can write. Postgres can take
 * that away properly:
 *
 *   CREATE ROLE lumina_ro LOGIN PASSWORD '…';
 *   GRANT CONNECT ON DATABASE ctomopdb TO lumina_ro;
 *   GRANT USAGE ON SCHEMA public TO lumina_ro;
 *   GRANT SELECT ON ALL TABLES IN SCHEMA public TO lumina_ro;
 *   ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO lumina_ro;
 *
 * Then point PATIENT_DATABASE_URL at lumina_ro. Read-only then holds at the
 * database, not merely by our good intentions — which is the only place a
 * guarantee like that is actually worth anything.
 */
class PatientManager implements PatientManagerInterface {

  /**
   * The columns every query in this class selects, and the aliases the hydrator
   * below expects. Defined once so find(), findMany() and search() cannot drift
   * apart — the classic way a "why is the name blank on this page only" bug is
   * born.
   *
   * `p` is person, `pi` is patient_info. The join is LEFT because patient_info
   * is a ctomop extension table: a person can exist without one, and losing the
   * whole row over a missing clinical record would be the wrong trade.
   */
  private const string SELECT_COLUMNS = <<<'SQL'
      p.person_id                        AS person_id,
      p.given_name                       AS given_name,
      p.family_name                      AS family_name,
      p.year_of_birth                    AS year_of_birth,
      pi.patient_age                     AS recorded_age,
      NULLIF(COALESCE(NULLIF(pi.gender, ''), p.gender_source_value), '') AS gender,
      pi.ethnicity                       AS ethnicity,
      pi.disease                         AS disease,
      pi.stage                           AS stage,
      pi.ecog_performance_status         AS ecog,
      pi.karnofsky_performance_score     AS karnofsky
    SQL;

  private const string FROM_JOIN = <<<'SQL'
    FROM person p
    LEFT JOIN patient_info pi ON pi.person_id = p.person_id
    SQL;

  /**
   * @param Connection $patients The `patients` DBAL connection from
   *   config/packages/doctrine.yaml. The #[Autowire] attribute names the service
   *   explicitly because autowiring a bare `Connection` would hand us the
   *   DEFAULT connection (lumina_db) and every query here would fail with
   *   "relation person does not exist" — a confusing way to find out.
   *
   *   doctrine-bundle also registers a named alias, so
   *   `Connection $patientsConnection` would work by argument name alone. The
   *   explicit service id is louder about intent, which is what you want at a
   *   boundary like this.
   *
   * @param LoggerInterface $logger Injected so a ctomop outage degrades into a
   *   logged warning plus an empty result, rather than a white screen on the
   *   evaluations list. Losing the patient *names* should not cost you the
   *   ability to read your evaluation *results*.
   */
  public function __construct(
    #[Autowire(service: 'doctrine.dbal.patients_connection')]
    private readonly Connection $patients,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritDoc}
   *
   * Implemented in terms of findMany() so there is exactly one hydration path.
   */
  public function find(int $personId): ?Patient {
    return $this->findMany([$personId])[$personId] ?? null;
  }

  /**
   * {@inheritDoc}
   *
   * Finds all patients and returns a keyed array.
   */
  public function findAll(): array {
    $sql = sprintf(
      'SELECT %s %s',
      self::SELECT_COLUMNS,
      self::FROM_JOIN,
    );

    $rows = $this->fetchAll($sql);

    $patients = [];
    foreach ($rows as $row) {
      $patient = $this->hydrate($row);
      // Keyed by person_id so callers can do $patients[$evaluation->getPersonId()]
      // instead of scanning the list once per evaluation row.
      $patients[$patient->personId] = $patient;
    }

    return $patients;
  }

  /**
   * {@inheritDoc}
   *
   * The interesting bit is ArrayParameterType::INTEGER: DBAL expands a bound
   * array into the right number of placeholders for `IN (?)` and binds each
   * element as an integer. Never build that list by imploding — this is the
   * safe, portable way.
   */
  public function findMany(array $personIds): array {
    // Normalise first: unique, integer-cast, no empties. Casting here means a
    // stray "12abc" from a query string becomes 12 rather than reaching the DB.
    $personIds = array_values(array_unique(array_map('intval', $personIds)));

    if ($personIds === []) {
      // Short-circuit: `IN ()` is a syntax error in Postgres, and there is no
      // point opening a connection to answer a question with no subjects.
      return [];
    }

    $sql = sprintf(
      'SELECT %s %s WHERE p.person_id IN (:ids)',
      self::SELECT_COLUMNS,
      self::FROM_JOIN,
    );

    $rows = $this->fetchAll($sql, ['ids' => $personIds], ['ids' => ArrayParameterType::INTEGER]);

    $patients = [];
    foreach ($rows as $row) {
      $patient = $this->hydrate($row);
      // Keyed by person_id so callers can do $patients[$evaluation->getPersonId()]
      // instead of scanning the list once per evaluation row.
      $patients[$patient->personId] = $patient;
    }

    return $patients;
  }

  /**
   * {@inheritDoc}
   *
   * TODO — this is yours to build. The pieces you need:
   *
   *   1. Start from self::SELECT_COLUMNS . self::FROM_JOIN, then append a WHERE
   *      only when $query is a non-empty string. Build the clause as a string
   *      and the values as a $params array in parallel; never interpolate.
   *
   *   2. Two search modes, decided by ctype_digit($query):
   *        - all digits  → 'p.person_id = :id'         (exact id lookup)
   *        - otherwise   → match the name. Postgres ILIKE is case-insensitive:
   *              "(p.given_name ILIKE :q OR p.family_name ILIKE :q)"
   *          with $params['q'] = '%' . $query . '%'.
   *          Careful: % and _ are wildcards *inside* the value, so a user typing
   *          "100%" searches oddly. addcslashes($query, '%_\\') plus an
   *          "ESCAPE '\'" suffix fixes that. Worth doing once you have it working.
   *
   *   3. ORDER BY must be deterministic or pagination silently repeats rows:
   *      'ORDER BY p.family_name NULLS LAST, p.given_name NULLS LAST, p.person_id'
   *      — the trailing person_id is the tiebreaker that makes it total.
   *
   *   4. LIMIT/OFFSET: bind these as parameters too, but ALSO clamp them in PHP
   *      (e.g. $limit = max(1, min($limit, 100)); $offset = max(0, $offset)).
   *      A bound parameter stops injection; it does not stop somebody asking for
   *      a million rows.
   *
   *   5. Map with $this->hydrate() and return a plain list (array_map is fine —
   *      unlike findMany, a search result wants order, not id keys).
   *
   * With only 107 patients you will not feel the difference, but writing it this
   * way now means the page still works when ctomop holds 107,000.
   */
  public function search(?string $query = null, int $limit = 25, int $offset = 0): array {
    throw new \LogicException(__METHOD__ . ' is not implemented yet — see the TODO above.');
  }

  /**
   * {@inheritDoc}
   *
   * TODO — yours as well, and it must share its WHERE clause with search() or the
   * two will disagree and your pager will lie. The usual fix is to extract a
   * small private helper:
   *
   *     private function buildFilter(?string $query): array  // [$whereSql, $params]
   *
   * returning '' and [] for the no-filter case, then have both methods call it.
   * COUNT(*) needs the join only if you filter on patient_info columns; keeping
   * the same FROM/JOIN in both is the simpler, harder-to-get-wrong choice.
   */
  public function countAll(?string $query = null): int {
    throw new \LogicException(__METHOD__ . ' is not implemented yet — see the TODO above.');
  }

  // ── Internals ─────────────────────────────────────────────────────────────

  /**
   * Runs a query and returns rows, converting a dead/unreachable ctomop into a
   * logged warning plus an empty result set.
   *
   * The graceful-degradation call: a patient database we cannot reach should
   * cost you patient *names*, not the whole evaluations screen. Callers get [],
   * templates fall back to "Patient 1234", and the operator gets a real log line
   * naming the failure. Silence would be worse than either.
   *
   * @param array<string, mixed> $params
   * @param array<string, mixed> $types
   * @return array<int, array<string, mixed>>
   */
  private function fetchAll(string $sql, array $params = [], array $types = []): array {
    try {
      return $this->patients->fetchAllAssociative($sql, $params, $types);
    }
    catch (DbalException $e) {
      $this->logger->warning('ctomop patient lookup failed: {message}', [
        'message' => $e->getMessage(),
        'exception' => $e,
        // Log the SQL, never the params — those can carry patient identifiers,
        // and log files are a much less careful place than a database.
        'sql' => $sql,
      ]);

      return [];
    }
  }

  /**
   * Turns one raw result row into a Patient.
   *
   * Kept private and separate so the column→property mapping lives in exactly
   * one place: when ctomop renames a column, this method is the only thing that
   * needs to change.
   *
   * Note every cast is null-guarded. Postgres hands back nulls for the many
   * unpopulated columns in the synthetic dataset, and `(int) null` is 0 — which
   * would quietly turn "ECOG unknown" into "ECOG 0, fully active". That is a
   * clinically meaningful lie, so nulls stay null all the way to the template.
   *
   * @param array<string, mixed> $row
   */
  private function hydrate(array $row): Patient {
    return new Patient(
      personId: (int) $row['person_id'],
      givenName: $this->nullableString($row['given_name'] ?? null),
      familyName: $this->nullableString($row['family_name'] ?? null),
      yearOfBirth: $this->nullableInt($row['year_of_birth'] ?? null),
      recordedAge: $this->nullableInt($row['recorded_age'] ?? null),
      gender: $this->nullableString($row['gender'] ?? null),
      ethnicity: $this->nullableString($row['ethnicity'] ?? null),
      disease: $this->nullableString($row['disease'] ?? null),
      stage: $this->nullableString($row['stage'] ?? null),
      ecogPerformanceStatus: $this->nullableInt($row['ecog'] ?? null),
      karnofskyPerformanceScore: $this->nullableInt($row['karnofsky'] ?? null),
    );
  }

  /** Trims, then treats an empty string as "no value recorded". */
  private function nullableString(mixed $value): ?string {
    if ($value === null) {
      return null;
    }

    $value = trim((string) $value);

    return $value === '' ? null : $value;
  }

  /** Null stays null; anything else becomes an int. See hydrate() for why. */
  private function nullableInt(mixed $value): ?int {
    return $value === null || $value === '' ? null : (int) $value;
  }
}

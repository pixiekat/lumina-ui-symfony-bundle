<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\LuminaUiBundle\Entity\Evaluation;
use Pixiekat\LuminaUiBundle\Enum\EvaluationStatus;

/**
 * @extends ServiceEntityRepository<Evaluation>
 *
 * Note: we intentionally do NOT use the shared CacheableFindAll trait here.
 * This is a live status table — rows flip Pending → Running → Completed as
 * background workers run — so a cached findAll() would show stale statuses.
 */
class EvaluationRepository extends ServiceEntityRepository {

  public function __construct(ManagerRegistry $registry) {
    parent::__construct($registry, Evaluation::class);
  }

  /**
   * One page of STANDALONE evaluations, most-recent-first, for the index table.
   *
   * Batch-produced rows (those with a batch set) are excluded — they're shown
   * grouped under their batch on /batches/{id}, so the main Evaluations list
   * stays focused on directly-run evaluations rather than being flooded by a
   * single "Run all" of 100+ patients.
   *
   * Returns a Doctrine Paginator (Countable + iterable): count($paginator) is the
   * grand total across all pages; iterating yields only this page's rows.
   */
  public function paginateStandalone(int $page, int $perPage = 25): Paginator {
    $page = max(1, $page);

    $query = $this->createQueryBuilder('e')
      ->andWhere('e.batch IS NULL')
      ->orderBy('e.createdAt', 'DESC')
      ->addOrderBy('e.id', 'DESC')
      ->setFirstResult(($page - 1) * $perPage)
      ->setMaxResults($perPage)
      ->getQuery();

    // No to-many joins in the query, so the cheaper count strategy is safe.
    return new Paginator($query, fetchJoinCollection: false);
  }

  /**
   * The most recent evaluation for each patient against ONE trial, keyed by
   * person_id.
   *
   * This is what drives the "select a trial, then queue patients" screen. The
   * queue button's state is DERIVED from this map rather than tracked in the
   * browser: no row → offer "Queue"; a non-terminal row → show the status and
   * offer nothing; a terminal row → show the status and offer "Re-run". Because
   * it is recomputed on every render, a page refresh, a back-navigation, or a
   * colleague queueing the same patient in another tab all resolve correctly on
   * their own. A JavaScript-disabled button would simply re-enable itself on
   * reload, which is why the truth lives here.
   *
   * One query for the whole page, so patient count does not drive query count.
   *
   * Ordered ASC and overwritten in the loop so the highest id wins per person.
   * With a few hundred rows that is cheaper to read and to run than a correlated
   * subquery. If this table ever grows large, the Postgres-native replacement is
   * `SELECT DISTINCT ON (person_id) … ORDER BY person_id, id DESC` over raw DBAL
   * — DQL has no DISTINCT ON.
   *
   * @return array<int, Evaluation> Keyed by person_id.
   */
  public function findLatestByTrialIndexedByPerson(int $trialId): array {
    $rows = $this->createQueryBuilder('e')
      ->andWhere('e.trialId = :trial')
      ->setParameter('trial', $trialId)
      ->orderBy('e.id', 'ASC')
      ->getQuery()
      ->getResult();

    $latest = [];
    foreach ($rows as $evaluation) {
      $latest[$evaluation->getPersonId()] = $evaluation;
    }

    return $latest;
  }

  /**
   * An in-flight (pending or running) evaluation for this patient × trial, if
   * one exists.
   *
   * Used as a friendly pre-check before queueing, so a double-click gets the
   * message "already queued" instead of a constraint violation. It is NOT the
   * real guarantee — two simultaneous requests can both pass this check before
   * either inserts. The partial unique index added in
   * Version20260730120000 is what actually enforces it; this method just makes
   * the common case produce a kind error rather than an ugly one.
   */
  public function findActiveFor(int $trialId, int $personId): ?Evaluation {
    return $this->createQueryBuilder('e')
      ->andWhere('e.trialId = :trial')
      ->andWhere('e.personId = :person')
      ->andWhere('e.status IN (:active)')
      ->setParameter('trial', $trialId)
      ->setParameter('person', $personId)
      // Enum cases are passed by value: the column stores the backing strings.
      ->setParameter('active', [EvaluationStatus::Pending->value, EvaluationStatus::Running->value])
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
  }

  /**
   * How many evaluations in a batch currently hold one of the given statuses.
   *
   * Deliberately a COUNT query rather than iterating $batch->getEvaluations():
   * that collection is populated through the entity manager's identity map, so a
   * worker holding an older snapshot could roll a batch up as "finished" while a
   * sibling row it never re-read is still running. Asking the database each time
   * removes the possibility.
   *
   * @param EvaluationStatus[] $statuses
   */
  public function countInBatchWithStatus(int $batchId, array $statuses): int {
    return (int) $this->createQueryBuilder('e')
      ->select('COUNT(e.id)')
      ->andWhere('e.batch = :batch')
      ->andWhere('e.status IN (:statuses)')
      ->setParameter('batch', $batchId)
      // Enum cases bind by backing value; the column stores the strings.
      ->setParameter('statuses', array_map(static fn(EvaluationStatus $s): string => $s->value, $statuses))
      ->getQuery()
      ->getSingleScalarResult();
  }

  /**
   * One row per trial that has ever been evaluated, with its run counts.
   *
   * This is the whole data set behind the "Trials" index — the grouped view that
   * answers "which trials have we actually run, and when did we last touch them?"
   * without loading a single Evaluation object.
   *
   * ── Why a scalar aggregate rather than hydrated entities ───────────────────
   * The alternative is findAll() plus grouping in PHP, which pulls every row —
   * including the two TEXT columns (`raw_output` is whole console captures) — just
   * to throw them away after counting. GROUP BY does the arithmetic in the
   * database and returns a handful of rows. It also means the page cost stays
   * flat as the evaluation table grows.
   *
   * Rows with a null trialId are the `search_trials_for_patients` kind: one
   * patient across ALL trials, so they have no trial to group under. They are
   * excluded here and remain visible via the batches screens.
   *
   * ── The shape you get back ─────────────────────────────────────────────────
   * Aggregate functions bypass Doctrine's type conversion: MAX() over a
   * datetime_immutable column comes back as the DRIVER's value — a raw
   * 'YYYY-MM-DD HH:II:SS' string on Postgres, not a \DateTimeImmutable. Callers
   * must convert; ReadModel\TrialEvaluationGroup::fromAggregateRow() is the one
   * place that does, so the parsing rule lives in exactly one file.
   *
   * @return list<array{trialId: int, evaluationCount: int, lastRanAt: ?string, lastQueuedAt: ?string}>
   */
  public function findTrialGroups(): array {
    return $this->createQueryBuilder('e')
      ->select(
        'e.trialId AS trialId',
        'COUNT(e.id) AS evaluationCount',
        // Actual completion time. Null for a trial whose runs are all still
        // pending — hence the second aggregate below as a fallback.
        'MAX(e.ranAt) AS lastRanAt',
        'MAX(e.createdAt) AS lastQueuedAt',
      )
      ->andWhere('e.trialId IS NOT NULL')
      ->groupBy('e.trialId')
      // Ordering by the SELECT alias (a DQL "result variable") rather than by
      // MAX(...) again: one definition, and no risk of the two drifting apart.
      // Newest activity first, with trialId as the tiebreaker so the order is
      // total — without it, two trials queued in the same second could swap
      // places between requests and a pager would repeat or skip rows.
      ->orderBy('lastQueuedAt', 'DESC')
      ->addOrderBy('trialId', 'DESC')
      ->getQuery()
      ->getResult();
  }

  /**
   * Every evaluation recorded against ONE trial, newest first.
   *
   * Deliberately NOT sorted by match count here, and deliberately not paginated:
   * the "matches" a result is ranked by are a count of entries inside the
   * `attributes` JSON column, and neither DQL nor a portable SQL expression can
   * ORDER BY that. The controller sorts the hydrated rows in PHP — see
   * TrialController::show().
   *
   * ── What that costs, and when to change it ─────────────────────────────────
   * The bound is (patients × re-runs) for a single trial: a few hundred rows in
   * this deployment, which is nothing. It does mean the whole set is materialised
   * before a page of it is shown, so if a trial ever accumulates tens of
   * thousands of runs, the Postgres-native replacement is to sort in the database
   * over raw DBAL:
   *
   *     ORDER BY (
   *       SELECT count(*) FROM jsonb_array_elements(e.attributes::jsonb) a
   *       WHERE a->>'status' = 'matched'
   *     ) DESC
   *
   * — correct but Postgres-only, and worth an expression index at that size. Do
   * not reach for it before the row count justifies losing the portability.
   *
   * @return Evaluation[]
   */
  public function findByTrial(int $trialId): array {
    return $this->createQueryBuilder('e')
      ->andWhere('e.trialId = :trial')
      ->setParameter('trial', $trialId)
      ->orderBy('e.createdAt', 'DESC')
      ->addOrderBy('e.id', 'DESC')
      ->getQuery()
      ->getResult();
  }

  /** Persist + flush a single evaluation. Small convenience for handlers/controllers. */
  public function save(Evaluation $evaluation, bool $flush = true): void {
    $em = $this->getEntityManager();
    $em->persist($evaluation);
    if ($flush) {
      $em->flush();
    }
  }
}

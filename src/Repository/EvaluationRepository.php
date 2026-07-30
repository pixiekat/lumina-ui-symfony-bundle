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

  /** Persist + flush a single evaluation. Small convenience for handlers/controllers. */
  public function save(Evaluation $evaluation, bool $flush = true): void {
    $em = $this->getEntityManager();
    $em->persist($evaluation);
    if ($flush) {
      $em->flush();
    }
  }
}

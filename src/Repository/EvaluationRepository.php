<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\LuminaUiBundle\Entity\Evaluation;

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

  /** Persist + flush a single evaluation. Small convenience for handlers/controllers. */
  public function save(Evaluation $evaluation, bool $flush = true): void {
    $em = $this->getEntityManager();
    $em->persist($evaluation);
    if ($flush) {
      $em->flush();
    }
  }
}

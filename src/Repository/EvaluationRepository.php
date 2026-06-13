<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
   * Most-recent-first list for the index table.
   *
   * @return Evaluation[]
   */
  public function findLatest(int $limit = 100): array {
    return $this->createQueryBuilder('e')
      ->orderBy('e.createdAt', 'DESC')
      ->setMaxResults($limit)
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

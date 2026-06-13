<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\LuminaUiBundle\Entity\EvaluationBatch;

/**
 * @extends ServiceEntityRepository<EvaluationBatch>
 */
class EvaluationBatchRepository extends ServiceEntityRepository {

  public function __construct(ManagerRegistry $registry) {
    parent::__construct($registry, EvaluationBatch::class);
  }

  /**
   * Most-recent-first list for the batches index table.
   *
   * @return EvaluationBatch[]
   */
  public function findLatest(int $limit = 100): array {
    return $this->createQueryBuilder('b')
      ->orderBy('b.createdAt', 'DESC')
      ->setMaxResults($limit)
      ->getQuery()
      ->getResult();
  }

  /** Persist + flush a single batch. */
  public function save(EvaluationBatch $batch, bool $flush = true): void {
    $em = $this->getEntityManager();
    $em->persist($batch);
    if ($flush) {
      $em->flush();
    }
  }
}

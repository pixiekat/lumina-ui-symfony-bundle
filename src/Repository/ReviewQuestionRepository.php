<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\LuminaUiBundle\Entity\ReviewQuestion;

/**
 * @extends ServiceEntityRepository<ReviewQuestion>
 *
 * The reasons offered when an evaluator disagrees. A tiny, rarely-changing
 * table — three rows out of the box — read on every evaluation page.
 */
class ReviewQuestionRepository extends ServiceEntityRepository {

  public function __construct(ManagerRegistry $registry) {
    parent::__construct($registry, ReviewQuestion::class);
  }

  /**
   * The reasons to offer in the dropdown, in display order.
   *
   * Retired reasons are excluded here rather than in the template, so there is
   * exactly one definition of "a reason you may choose today" and no chance of a
   * second screen quietly offering a deactivated one.
   *
   * Ordered by weight then id: weight is what an admin sets to promote a common
   * reason to the top, and id breaks ties so two reasons left at the default
   * weight of 0 keep a stable, predictable order instead of arriving in whatever
   * sequence Postgres felt like.
   *
   * @return ReviewQuestion[]
   */
  public function findActiveOrdered(): array {
    return $this->createQueryBuilder('q')
      ->andWhere('q.active = true')
      ->orderBy('q.weight', 'ASC')
      ->addOrderBy('q.id', 'ASC')
      ->getQuery()
      ->getResult();
  }

  /**
   * Every reason, active or not, in the same order — for the admin screen, which
   * has to show retired ones in order to offer them back.
   *
   * @return ReviewQuestion[]
   */
  public function findAllOrdered(): array {
    return $this->createQueryBuilder('q')
      ->orderBy('q.weight', 'ASC')
      ->addOrderBy('q.id', 'ASC')
      ->getQuery()
      ->getResult();
  }

  /**
   * Look up a reason that may legitimately be chosen right now.
   *
   * Used by the controller when saving a review. It deliberately re-checks
   * `active` rather than trusting the id that came back in the POST: the
   * dropdown was rendered from findActiveOrdered(), but a form left open while
   * an admin retired that reason would otherwise write a dead reason to a brand
   * new review. Anything the browser sends is a claim, not a fact.
   */
  public function findActiveById(int $id): ?ReviewQuestion {
    return $this->createQueryBuilder('q')
      ->andWhere('q.id = :id')
      ->andWhere('q.active = true')
      ->setParameter('id', $id)
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
  }
}

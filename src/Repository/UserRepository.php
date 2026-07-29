<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Repository;

use Pixiekat\LuminaUiBundle\Entity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<Entity\User>
 * @implements PasswordUpgraderInterface<Entity\User>
 *
 * @method Entity\User|null find($id, $lockMode = null, $lockVersion = null)
 * @method Entity\User|null findOneBy(array $criteria, array $orderBy = null)
 * @method Entity\User[]    findAll()
 * @method Entity\User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface {

  public function __construct(
    ManagerRegistry $registry,
    private EntityManagerInterface $entityManager,
    private Security $security
  ) {
    parent::__construct($registry, Entity\User::class);
  }

  /**
   * finds all active users.
   */
  public function findAllActiveUsers(): array {
    return $this->createQueryBuilder('u')
      ->andWhere('u.active = :active')
      ->setParameter('active', true)
      ->getQuery()
      ->getResult();
  }

  /**
   * finds all active users with the given role.
   */
  public function findAllActiveUsersWithRole(string $role): array {
    return $this->createQueryBuilder('u')
      ->andWhere('u.active = :active')
      ->andWhere('u.roles LIKE :role')
      ->setParameter('active', true)
      ->setParameter('role', '%"'.$role.'"%')
      ->getQuery()
      ->getResult();
  }

  /**
   * Used to upgrade (rehash) the user's password automatically over time.
   */
  public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void {
    if (!$user instanceof User) {
      throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
    }

    $user->setPassword($newHashedPassword);
    $this->getEntityManager()->persist($user);
    $this->getEntityManager()->flush();
  }
}

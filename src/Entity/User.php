<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Application user.
 *
 * Created primarily because pixiekat/symfony-common-helpers references
 * Pixiekat\LuminaUiBundle\Entity\User (its ResetPasswordRequest has a ManyToOne to this class), so
 * the class must exist and be mapped for the ORM metadata to resolve.
 *
 * It is a fully-formed Security user (UserInterface + PasswordAuthenticatedUserInterface)
 * built from the shared helper traits, BUT authentication is NOT wired to it yet:
 * security.yaml still uses the in-memory provider. When real DB-backed users are
 * needed, switch the provider to an entity provider on this class. ("Users can
 * wait" — this is just the scaffolding so the rest of the app boots.)
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'users_email_unique', columns: ['email'])]
#[ORM\RepositoryClass('Pixiekat\LuminaUiBundle\Repository\UserRepository')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface {
  use PixieTraits\EntityDisplayNameTrait;  // getDisplayName() from first/middle/last name or email
  use PixieTraits\EntityIdTrait;            // id + getId()
  use PixieTraits\EntityPasswordTrait;      // password column + getPassword()
  use PixieTraits\EntityRolesTrait;         // roles column + getRoles()
  use PixieTraits\EntityEmailAddressTrait;  // getEmail()/setEmail() over the $email property below
  use PixieTraits\EntityActiveTrait;
  use PixieTraits\EntityCreatedAtTrait;     // createdAt column + getCreatedAt()

  #[ORM\Column(type: 'string', nullable: true)]
  private ?string $authCode = null;

  #[ORM\Column(length: 180, unique: true)]
  private ?string $email = null;

  public function __construct() {
    $this->setActive(true);
    $this->setCreatedAt(new \DateTimeImmutable());
  }

  /** The unique identifier Symfony Security uses for this user. */
  public function getUserIdentifier(): string {
    return (string) $this->email;
  }

  /** No transient/plaintext secrets are held on the entity, so nothing to erase. */
  public function eraseCredentials(): void {
  }

  public function isEmailAuthEnabled(): bool {
    return true; // always true for now, but could be made conditional on a user property if needed
  }

  public function getEmailAuthRecipient(): string {
    return $this->getEmailAddress();
  }

  public function getEmailAuthCode(): string {
    if (null === $this->authCode) {
        throw new \LogicException('The email authentication code was not set');
    }

    return $this->authCode;
  }

  public function setEmailAuthCode(string $authCode): void {
    $this->authCode = $authCode;
  }
}

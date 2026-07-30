<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Security\Voter;

use Pixiekat\LuminaUiBundle\Entity;
use Pixiekat\LuminaUiBundle\Interfaces;
use Pixiekat\SymfonyHelpers\Security as PixieHelperSecurity;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

class GenericVoter extends PixieHelperSecurity\Voter\BaseVoter implements Interfaces\Security\Voter\GenericVoterInterface {

  protected function supports(string $attribute, mixed $subject): bool {
    $attributes = $this->getAttributes();
    return in_array($attribute, $attributes);
  }

  protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool {
    $user = $token->getUser();

    if (!$user instanceof UserInterface) {
      return false;
    }

    if ($this->isSysAdmin()) {
      return true;
    }

    return match($attribute) {
        self::CAN_ACCESS_EVALUATIONS => $this->security->isGranted('ROLE_EVALUATOR') || $this->security->isGranted('ROLE_ADMIN'),
        self::CAN_RUN_EVALUATIONS => $this->security->isGranted('ROLE_ADMIN'),
        self::CAN_ACCESS_ADMIN_PANEL => $this->security->isGranted('ROLE_ADMIN'),
        self::CAN_LOG_IN => $this->canLogIn($user),
        default => false,
    };

    return false;
  }

  private function canLogIn(UserInterface $user): bool {
    if (!$user instanceof Entity\User) {
      return false;
    }

    // check if the user is active
    if (!$user->isActive()) {
      return false;
    }

    // in the future, we may want to add more checks here, such as checking if the user has 2FA enabled and if they have completed the 2FA process. For now, we will just check if the user is active.

    return true;
  }
}

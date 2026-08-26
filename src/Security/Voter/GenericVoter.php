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

    $forceForAdmins = [self::CAN_EDIT_EVALUATION_REVIEW];

    if ($this->isSysAdmin() && !in_array($attribute, $forceForAdmins)) {
      return true;
    }

    return match($attribute) {
        self::CAN_ACCESS_EVALUATIONS => $this->security->isGranted('ROLE_USER'),
        self::CAN_VIEW_EVALUATION_JSON => $this->security->isGranted('ROLE_ADMIN'),
        self::CAN_RUN_EVALUATIONS => $this->security->isGranted('ROLE_EVALUATOR') || $this->security->isGranted('ROLE_ADMIN'),
        self::CAN_DELETE_EVALUATIONS => $this->security->isGranted('ROLE_ADMIN'),
        // Recording a verdict is the evaluator's job by definition. Admins get it
        // too via the role hierarchy in security.yaml (ROLE_ADMIN → ROLE_EVALUATOR),
        // so the second check is redundant today — it is spelled out to match the
        // line above and to survive the hierarchy being rearranged.
        self::CAN_REVIEW_EVALUATIONS => $this->security->isGranted('ROLE_EVALUATOR') || $this->security->isGranted('ROLE_ADMIN'),
        self::CAN_VIEW_EVALUATION_REVIEWS => $this->security->isGranted('ROLE_ADMIN'),
        self::CAN_VIEW_EVALUATION_SUMMARY => $this->security->isGranted('ROLE_EVALUATOR') || $this->security->isGranted('ROLE_ADMIN'),
        self::CAN_EDIT_EVALUATION_REVIEW => false, // For now, remove for everyone. In the future, we may want to allow evaluators to edit their own reviews, but for now, we will just disable this feature.
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

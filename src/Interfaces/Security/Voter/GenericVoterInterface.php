<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Interfaces\Security\Voter;

interface GenericVoterInterface {

  // determines whether or not a user can log in to the system, used in the login form to determine if the user can log in or not. If they cannot log in, they will be redirected to the login page with an error message.
  public const CAN_LOG_IN = 'CAN_LOGIN';

  // determines whether or not a user can access evaluations.
  public const CAN_ACCESS_EVALUATIONS = 'CAN_ACCESS_EVALUATIONS';

  // determines whether or not a user can run evaluations.
  public const CAN_RUN_EVALUATIONS = 'CAN_RUN_EVALUATIONS';

  // determines whether or not a user can access the admin panel.
  public const CAN_ACCESS_ADMIN_PANEL = 'CAN_ACCESS_ADMIN_PANEL';

}

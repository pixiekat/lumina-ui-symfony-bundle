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

  // determines whether or not a user can delete evaluations.
  public const CAN_DELETE_EVALUATIONS = 'CAN_DELETE_EVALUATIONS';

  // determines if a user can view the raw evaluation json data. This is a separate permission from CAN_ACCESS_EVALUATIONS because it is a more sensitive permission, and should only be granted to users who need it.
  public const CAN_VIEW_EVALUATION_JSON = 'CAN_VIEW_EVALUATION_JSON';

  // determines whether or not a user can record their own agree/disagree verdict
  // on an evaluation. Kept separate from CAN_ACCESS_EVALUATIONS on purpose: being
  // allowed to READ a result and being allowed to sign your name to an opinion
  // about it are different permissions, and a future read-only role (an observer,
  // an auditor) should be able to have the first without the second.
  public const CAN_REVIEW_EVALUATIONS = 'CAN_REVIEW_EVALUATIONS';

  // determines whether or not a user can edit their own review of an evaluation.
  public const CAN_EDIT_EVALUATION_REVIEW = 'CAN_EDIT_EVALUATION_REVIEW';

  // determines whether or not a user can view all the reviews of an evaluation. They will always be able to view a summary of the reviews, but not the individual reviews themselves.
  public const CAN_VIEW_EVALUATION_REVIEWS = 'CAN_VIEW_EVALUATION_REVIEWS';

  // determines whether or not a user can view a summary of the reviews of an evaluation. They will always be able to view a summary of the reviews, but not the individual reviews themselves.
  public const CAN_VIEW_EVALUATION_SUMMARY = 'CAN_VIEW_EVALUATION_SUMMARY';

  // determines whether or not a user can access the admin panel.
  public const CAN_ACCESS_ADMIN_PANEL = 'CAN_ACCESS_ADMIN_PANEL';

}

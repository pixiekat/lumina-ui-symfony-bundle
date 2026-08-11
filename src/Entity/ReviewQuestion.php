<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Pixiekat\LuminaUiBundle\Repository\ReviewQuestionRepository;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;

/**
 * ReviewQuestion
 * ==============
 *
 * One selectable answer to the question an evaluator is asked when they
 * disagree with an evaluation: *why?*
 *
 * Three of these are seeded by the migration, which is what makes the dropdown
 * on the evaluation page a three-item list. Nothing in the code depends on there
 * being exactly three — add a fourth in the admin screen and it appears.
 *
 * ── Why a table and not an enum ─────────────────────────────────────────────
 * The wording of a reason is a question of clinical vocabulary, not of program
 * logic. Nothing in this bundle branches on WHICH reason was chosen; it is
 * recorded, tallied and displayed. That makes it data, and data that a domain
 * expert should be able to reword at 4pm without waiting for a deploy. The
 * counterpart decision — verdicts, which the code branches on constantly — went
 * the other way; see Enum\ReviewVerdict for the full reasoning.
 *
 * ── Retire, do not delete ───────────────────────────────────────────────────
 * The admin screen offers "deactivate" and no delete, because a reason that has
 * been chosen is part of the historical record of why someone disagreed. Setting
 * `is_active` false removes it from the dropdown for new reviews while every
 * review that already cites it keeps reading correctly. (The foreign key is
 * nonetheless ON DELETE SET NULL, so a reason deleted straight out of psql
 * degrades to "reason no longer available" rather than taking the reviews with
 * it.)
 *
 * Column set comes entirely from the shared pixiekat/symfony-common-helpers
 * traits — id, label, description, weight, active flag, timestamps — so there is
 * no hand-written column on this class at all.
 */
#[ORM\Entity(repositoryClass: ReviewQuestionRepository::class)]
#[ORM\Table(name: 'lumina_review_question')]
#[ORM\HasLifecycleCallbacks]
class ReviewQuestion {
  use PixieTraits\EntityIdTrait;
  /** The text shown in the dropdown, e.g. "The patient data used is wrong". */
  use PixieTraits\EntityLabelTrait;
  /** Optional longer explanation, shown as help text under the dropdown. */
  use PixieTraits\EntityDescriptionTrait;
  /** Sort order — lowest first. Lets an admin promote a common reason. */
  use PixieTraits\EntityWeightTrait;
  /** Retired reasons stay in the table but leave the dropdown. */
  use PixieTraits\EntityActiveTrait;
  use PixieTraits\EntityCreatedAtTrait;
  use PixieTraits\EntityUpdatedAtTrait;

  public function __construct(?string $label = null, int $weight = 0) {
    $this->setLabel($label);
    $this->setWeight($weight);
    $this->setActive(true);
    // PrePersist sets this too; seeding it here means the value exists before
    // the entity is ever flushed, matching how Evaluation does it.
    $this->setCreatedAt(new \DateTimeImmutable());
  }

  /**
   * A never-empty display string.
   *
   * `label` is nullable in the shared trait (it is a generic column used by many
   * entities), so every template that prints one would otherwise need its own
   * `?? '—'`. One method here means one answer everywhere, including in the
   * admin table where a half-created row would otherwise render a blank cell
   * with no hint that anything is wrong.
   */
  public function displayLabel(): string {
    $label = $this->getLabel();

    return is_string($label) && trim($label) !== '' ? $label : '(untitled reason)';
  }
}

<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Enum;

/**
 * An evaluator's verdict on one evaluation: do they agree with what the
 * matching software concluded, or not?
 *
 * ── Why this is an enum when the REASONS are an entity ──────────────────────
 * These two cases are the feature itself. "Agree or disagree" is not a list that
 * grows: adding a third case would mean redesigning the tally, the badge, the
 * summary sentence and the form, so putting it in a database table would buy
 * nothing except the ability to break all four by editing a row. The reasons for
 * disagreeing are the opposite — a list we expect to reword and extend as
 * evaluators use the screen — so those live in Entity\ReviewQuestion where an
 * admin can change them without a deploy.
 *
 * The rule of thumb this is an instance of: model something as an enum when the
 * CODE has to branch on each case, and as a table when it does not. Nothing in
 * this bundle branches on which reason was picked; plenty branches on agree vs
 * disagree.
 */
enum ReviewVerdict: string {
  case Agree = 'agree';
  case Disagree = 'disagree';

  /**
   * The full sentence shown on buttons and in the comment list.
   *
   * Deliberately first-person and complete rather than a bare "Agree": the radio
   * labels, the "you already reviewed this" line and the per-comment badge all
   * read better as a statement, and a screen reader announcing "I agree with
   * this evaluation" needs no surrounding context to make sense of it.
   */
  public function label(): string {
    return match ($this) {
      self::Agree => 'I agree with this evaluation',
      self::Disagree => 'I disagree with this evaluation',
    };
  }

  /** The one-word form, for table cells and badges where space is tight. */
  public function shortLabel(): string {
    return match ($this) {
      self::Agree => 'Agree',
      self::Disagree => 'Disagree',
    };
  }

  /**
   * Whether picking this verdict obliges the evaluator to choose a reason.
   *
   * Asked by the controller before it validates, and by the template when it
   * decides whether to mark the reason dropdown required. Keeping the rule here
   * rather than writing `=== self::Disagree` in both places means there is one
   * definition to change if "agree" ever grows reasons of its own.
   */
  public function requiresReason(): bool {
    return $this === self::Disagree;
  }
}

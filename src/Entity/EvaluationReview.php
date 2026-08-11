<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;
use Pixiekat\LuminaUiBundle\Enum\ReviewVerdict;
use Pixiekat\LuminaUiBundle\Repository\EvaluationReviewRepository;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;

/**
 * EvaluationReview
 * ================
 *
 * One evaluator's human verdict on one Evaluation: "I agree with this" or "I
 * disagree, because <reason>", plus optional brief comments.
 *
 * An Evaluation is what the matching software concluded. This is what a person
 * concluded about that conclusion — which is the whole point of the screen, and
 * why the two are separate tables rather than extra columns on Evaluation. A
 * re-run creates a NEW Evaluation row (see EvaluationController::rerun), so
 * folding the human verdict into it would silently discard the review every time
 * anyone re-ran the match.
 *
 * ── One review per evaluator per evaluation, editable ───────────────────────
 * The unique constraint below is the whole storage model: submitting the form a
 * second time UPDATES your row instead of adding one. That is what lets the
 * banner at the top of the page be an honest tally — "2 agree · 1 disagree"
 * means three people, not one person who changed their mind twice. The
 * controller looks for your existing row first; the constraint is what makes
 * that true even when two of your own tabs submit at the same moment.
 *
 * The cost is that a change of mind is not history: updating overwrites. If an
 * audit trail of verdict changes is ever wanted, the shape that gives it is an
 * append-only table plus a `SELECT DISTINCT ON (evaluation_id, reviewer_id) …
 * ORDER BY … id DESC` to find the current one — deliberately not built now,
 * because nothing has asked to see a superseded verdict.
 */
#[ORM\Entity(repositoryClass: EvaluationReviewRepository::class)]
#[ORM\Table(name: 'lumina_evaluation_review')]
// The constraint that enforces "one verdict per evaluator per evaluation". It is
// not a nicety: without it a double-submit or two open tabs would both insert,
// and the tally at the top of the page would start counting one person twice.
#[ORM\UniqueConstraint(name: 'uniq_review_evaluation_reviewer', columns: ['evaluation_id', 'reviewer_id'])]
#[ORM\HasLifecycleCallbacks]
class EvaluationReview {
  use PixieTraits\EntityIdTrait;
  use PixieTraits\EntityCreatedAtTrait;
  use PixieTraits\EntityUpdatedAtTrait;

  /**
   * The evaluation being reviewed.
   *
   * onDelete CASCADE at the database level: deleting an evaluation (which the
   * index page offers) takes its reviews with it. There is deliberately no
   * inverse `reviews` collection on Evaluation — the review screens ask the
   * repository for exactly what they need, which keeps the evaluation lists from
   * lazy-loading a collection per row.
   */
  #[ORM\ManyToOne(targetEntity: Evaluation::class)]
  #[ORM\JoinColumn(name: 'evaluation_id', nullable: false, onDelete: 'CASCADE')]
  private Evaluation $evaluation;

  /**
   * Who recorded it. Non-null: an anonymous review would be untallyable and
   * unattributable, and the route is behind CAN_REVIEW_EVALUATIONS anyway.
   */
  #[ORM\ManyToOne(targetEntity: User::class)]
  #[ORM\JoinColumn(name: 'reviewer_id', nullable: false, onDelete: 'CASCADE')]
  private User $reviewer;

  /** Agree or disagree. The only thing the tally counts. */
  #[ORM\Column(length: 16, enumType: ReviewVerdict::class)]
  private ReviewVerdict $verdict;

  /**
   * The chosen reason, when the verdict is "disagree".
   *
   * Nullable for two distinct situations that both have to be legal:
   *   - the verdict is "agree", where no reason is asked for; and
   *   - the reason row was deleted outright (ON DELETE SET NULL), which the
   *     admin UI does not offer but psql cannot be stopped from doing.
   * Templates therefore always guard on null rather than assuming a disagree
   * review has a reason attached.
   */
  #[ORM\ManyToOne(targetEntity: ReviewQuestion::class)]
  #[ORM\JoinColumn(name: 'question_id', nullable: true, onDelete: 'SET NULL')]
  private ?ReviewQuestion $question = null;

  /**
   * Free-text comments, shown at the bottom of the evaluation page.
   *
   * ── Why this is encrypted ──────────────────────────────────────────────────
   * It is the only field on this screen a human can type prose into, and the
   * prose will be about a specific patient's specific data ("her staging is III,
   * not II"). That is patient information arriving by the back door, so it gets
   * the same treatment as Evaluation::$patientName rather than being left in
   * plaintext because it happens to be optional.
   *
   * The price is that the column is ciphertext in the database: no SQL LIKE, no
   * ORDER BY, no full-text index on comments. Nothing needs those today — the
   * admin table sorts by date and the per-evaluation list is a handful of rows.
   * If a "search all comments" feature is ever wanted, that is the trade-off to
   * revisit, and removing the attribute plus re-saving every row is the undo.
   *
   * TEXT rather than VARCHAR on purpose: encryption inflates the stored string
   * well beyond the length of what was typed, so a bounded column would reject
   * comments that looked comfortably short in the textarea.
   */
  #[ORM\Column(type: Types::TEXT, nullable: true)]
  #[Encrypted]
  private ?string $comments = null;

  public function __construct(Evaluation $evaluation, User $reviewer, ReviewVerdict $verdict) {
    $this->evaluation = $evaluation;
    $this->reviewer = $reviewer;
    $this->verdict = $verdict;
    // Belt-and-suspenders, exactly as Evaluation does it: PrePersist also sets
    // this, but seeding it now means the value exists before the first flush.
    $this->setCreatedAt(new \DateTimeImmutable());
  }

  // --- Getters / setters -----------------------------------------------------

  public function getEvaluation(): Evaluation {
    return $this->evaluation;
  }

  public function getReviewer(): User {
    return $this->reviewer;
  }

  public function getVerdict(): ReviewVerdict {
    return $this->verdict;
  }

  public function setVerdict(ReviewVerdict $verdict): static {
    $this->verdict = $verdict;
    return $this;
  }

  public function getQuestion(): ?ReviewQuestion {
    return $this->question;
  }

  public function setQuestion(?ReviewQuestion $question): static {
    $this->question = $question;
    return $this;
  }

  public function getComments(): ?string {
    return $this->comments;
  }

  public function setComments(?string $comments): static {
    $this->comments = $comments;
    return $this;
  }

  // --- Derived helpers -------------------------------------------------------

  /** Convenience for templates: does this review carry any prose to show? */
  public function hasComments(): bool {
    return is_string($this->comments) && trim($this->comments) !== '';
  }

  /**
   * The reason text, or null when there isn't one.
   *
   * Wrapping the two-step `getQuestion()?->displayLabel()` here keeps the null
   * handling in one place: templates ask for `review.reasonLabel` and get either
   * a string or nothing, without needing to know that a review can legitimately
   * have lost its reason row.
   */
  public function getReasonLabel(): ?string {
    return $this->question?->displayLabel();
  }

  /**
   * When this review last said something new — the timestamp to show a reader.
   *
   * Editing a review overwrites it, so "created" stops being the honest answer
   * the moment someone changes their mind: a verdict recorded in June and
   * reversed in August is an August opinion. updatedAt is null until the first
   * edit (it is set by PreUpdate), hence the fallback.
   */
  public function getRecordedAt(): \DateTimeImmutable {
    return $this->getUpdatedAt() ?? $this->getCreatedAt();
  }

  /** True once the review has been edited at least once. Drives an "(edited)" note. */
  public function wasEdited(): bool {
    return $this->getUpdatedAt() !== null;
  }
}

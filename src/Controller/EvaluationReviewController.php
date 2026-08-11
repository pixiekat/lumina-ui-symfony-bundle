<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\LuminaUiBundle\Entity\Evaluation;
use Pixiekat\LuminaUiBundle\Entity\EvaluationReview;
use Pixiekat\LuminaUiBundle\Entity\User;
use Pixiekat\LuminaUiBundle\Enum\ReviewVerdict;
use Pixiekat\LuminaUiBundle\Interfaces as PixieInterfaces;
use Pixiekat\LuminaUiBundle\Repository\EvaluationRepository;
use Pixiekat\LuminaUiBundle\Repository\EvaluationReviewRepository;
use Pixiekat\LuminaUiBundle\Repository\ReviewQuestionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Recording an evaluator's agree / disagree verdict on an evaluation.
 *
 * One write route, deliberately. Reading reviews back is the evaluation page's
 * job (EvaluationController::show passes the tally and the comment list into the
 * template), and the admin overview lives in AdminController with the rest of
 * the admin screens. What is left here is the single POST that creates or
 * updates the current user's own review — so the permission that guards it,
 * CAN_REVIEW_EVALUATIONS, guards exactly one thing.
 *
 * A plain HTML form POST with a CSRF token, matching EvaluationController rather
 * than the Symfony Form types used in AdminController. The form is three fields
 * and no round-tripped object graph; a FormType would add a class and a template
 * indirection to validate "pick one of two radios". If it grows a fourth field
 * or per-field error rendering, that is the moment to promote it.
 *
 * Everything works with JavaScript disabled. The Stimulus controller on the form
 * only hides the reason dropdown when "agree" is selected; with no JS the
 * dropdown is simply always visible and labelled as applying to disagreement,
 * and the server-side rules below are unchanged either way — which is the point
 * of doing the validation here rather than in the browser.
 */
#[IsGranted(PixieInterfaces\Security\Voter\GenericVoterInterface::CAN_REVIEW_EVALUATIONS)]
class EvaluationReviewController extends AbstractController {

  /**
   * How much prose we accept in one comment.
   *
   * Enforced here as well as by the textarea's maxlength, because maxlength is a
   * browser convenience and not a constraint — anything can POST to this route.
   * Over-long input is TRUNCATED rather than rejected: the evaluator's verdict is
   * the valuable part of the submission and throwing the whole thing away over a
   * long comment would lose it. The flash message says when this happened.
   */
  private const int COMMENT_MAX_LENGTH = 2000;

  public function __construct(
    private readonly EvaluationRepository $evaluations,
    private readonly EvaluationReviewRepository $reviews,
    private readonly ReviewQuestionRepository $questions,
    private readonly EntityManagerInterface $em,
  ) {}

  /**
   * Create or update the current evaluator's review of one evaluation.
   *
   * There is no separate "edit" route. Because a reviewer may hold at most one
   * review per evaluation (enforced by a unique constraint — see the
   * EvaluationReview docblock), "submit the form again" and "edit my review" are
   * the same action, and modelling them as one route means the two can never
   * drift apart in their validation.
   */
  #[Route(
    '/evaluations/{id}/review',
    name: 'lumina_ui_evaluation_review',
    methods: ['POST'],
    requirements: ['id' => '\d+'],
  )]
  public function submit(int $id, Request $request): Response {
    $evaluation = $this->evaluations->find($id);
    if ($evaluation === null) {
      throw $this->createNotFoundException(sprintf('Evaluation #%d not found.', $id));
    }

    if (!$this->isCsrfTokenValid('review-' . $id, (string) $request->request->get('_token'))) {
      return $this->rejectWith($evaluation, 'Invalid security token — please try again.');
    }

    // The route is behind CAN_REVIEW_EVALUATIONS so there IS a logged-in user,
    // but a review needs the mapped entity specifically — the association is to
    // Entity\User, and any other UserInterface implementation (a test double, an
    // in-memory provider left over in some other host app) could not be
    // persisted. Failing loudly beats a confusing ORM error at flush time.
    $reviewer = $this->getUser();
    if (!$reviewer instanceof User) {
      throw $this->createAccessDeniedException('Only database-backed users can record a review.');
    }

    // ── Verdict: the one genuinely required field ─────────────────────────────
    // tryFrom rather than from: a value that is not one of the two cases means a
    // hand-rolled POST or a broken form, and that deserves the same friendly
    // error as any other bad input rather than a 500 from an uncaught ValueError.
    $verdict = ReviewVerdict::tryFrom((string) $request->request->get('verdict', ''));
    if ($verdict === null) {
      return $this->rejectWith($evaluation, 'Please choose whether you agree or disagree with this evaluation.');
    }

    // ── Reason: required when disagreeing, discarded when agreeing ────────────
    $question = null;
    if ($verdict->requiresReason()) {
      $questionId = $request->request->getInt('question');
      $question = $questionId > 0 ? $this->questions->findActiveById($questionId) : null;

      if ($question === null) {
        // Covers all three ways this can fail — nothing chosen, an id that does
        // not exist, and a reason retired since the page was rendered — with one
        // message, because the fix for the evaluator is identical in each case.
        return $this->rejectWith($evaluation, 'Please choose a reason for disagreeing with this evaluation.');
      }
    }
    // Note what does NOT happen in an else branch: `$question` stays null, so
    // switching an existing review from disagree to agree clears the stale
    // reason below rather than leaving "I agree, because the patient data is
    // wrong" in the database.

    // ── Comments: optional on both verdicts ───────────────────────────────────
    $comments = trim((string) $request->request->get('comments', ''));
    $wasTruncated = false;
    if (mb_strlen($comments) > self::COMMENT_MAX_LENGTH) {
      // mb_* throughout: the column holds prose that may contain accented
      // characters, and substr() would happily cut one in half.
      $comments = mb_substr($comments, 0, self::COMMENT_MAX_LENGTH);
      $wasTruncated = true;
    }

    // ── Create or update ──────────────────────────────────────────────────────
    $review = $this->reviews->findOneForReviewer($evaluation, $reviewer);
    $isNew = $review === null;

    if ($isNew) {
      $review = new EvaluationReview($evaluation, $reviewer, $verdict);
      $this->em->persist($review);
    }

    $review
      ->setVerdict($verdict)
      ->setQuestion($question)
      // Empty textarea means "no comment", not an empty string, so the column
      // stays null and hasComments() has one thing to test rather than two.
      ->setComments($comments === '' ? null : $comments);

    try {
      $this->em->flush();
    }
    catch (UniqueConstraintViolationException) {
      // The check-then-insert above is not atomic: two tabs submitting a first
      // review at the same instant can both find nothing and both try to insert.
      // The unique constraint settles it, and the loser gets a plain explanation
      // instead of a 500. Reloading shows the winner's row, which is theirs too.
      return $this->rejectWith(
        $evaluation,
        'Your review was saved by another tab a moment ago. Reload the page to see it.',
        'info',
      );
    }

    $this->addFlash('success', $wasTruncated
      ? sprintf('Review recorded — your comment was shortened to %d characters.', self::COMMENT_MAX_LENGTH)
      : ($isNew ? 'Thank you — your review has been recorded.' : 'Your review has been updated.'));

    return $this->backToEvaluation($evaluation);
  }

  /**
   * Flash a problem and send the evaluator back to the form.
   *
   * Every validation failure in this controller does exactly this, so it is
   * written once. Redirecting rather than re-rendering means a refresh cannot
   * re-submit the form (post/redirect/get), at the cost of not repopulating the
   * fields — an acceptable trade for a form of three controls, and the reason
   * each message says specifically what to fix.
   */
  private function rejectWith(Evaluation $evaluation, string $message, string $type = 'error'): Response {
    $this->addFlash($type, $message);

    return $this->backToEvaluation($evaluation);
  }

  /**
   * Back to the evaluation, scrolled to the review form.
   *
   * The fragment matters for long evaluations: the attribute breakdown can run to
   * dozens of rows, and landing at the top of the page after submitting a form
   * near the bottom leaves the evaluator hunting for whether anything happened.
   * The flash message is at the top, so this is a compromise — see the note in
   * _review_form.html.twig about the form announcing its own state as well.
   */
  private function backToEvaluation(Evaluation $evaluation): Response {
    return $this->redirect(
      $this->generateUrl('lumina_ui_evaluation_show', ['id' => $evaluation->getId()]) . '#review',
    );
  }
}

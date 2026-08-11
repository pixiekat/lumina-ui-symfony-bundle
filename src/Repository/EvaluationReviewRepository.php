<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\LuminaUiBundle\Entity\Evaluation;
use Pixiekat\LuminaUiBundle\Entity\EvaluationReview;
use Pixiekat\LuminaUiBundle\Entity\User;
use Pixiekat\LuminaUiBundle\Enum\ReviewVerdict;

/**
 * @extends ServiceEntityRepository<EvaluationReview>
 *
 * Human verdicts on evaluations. Three questions get asked of this table, and
 * there is one method for each: "what did I say?", "what did everyone say?" and
 * "how does it add up?".
 */
class EvaluationReviewRepository extends ServiceEntityRepository {

  public function __construct(ManagerRegistry $registry) {
    parent::__construct($registry, EvaluationReview::class);
  }

  /**
   * This evaluator's own review of this evaluation, if they have recorded one.
   *
   * Drives two things at once: whether the form says "Record my review" or
   * "Update your review", and whether the controller creates a row or edits the
   * existing one. Both callers want the same row, so both ask the same question.
   *
   * The unique constraint guarantees there is at most one, which is why this can
   * safely return a single result rather than "the newest".
   */
  public function findOneForReviewer(Evaluation $evaluation, User $reviewer): ?EvaluationReview {
    return $this->createQueryBuilder('r')
      ->andWhere('r.evaluation = :evaluation')
      ->andWhere('r.reviewer = :reviewer')
      ->setParameter('evaluation', $evaluation)
      ->setParameter('reviewer', $reviewer)
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
  }

  /**
   * Every review of one evaluation, newest activity first — the comment list at
   * the bottom of the page.
   *
   * Ordering is by the same "when did this last say something new" rule the
   * entity exposes as getRecordedAt(): an edited review moves back to the top,
   * because a verdict reversed this morning is more current than one recorded
   * unchanged in June. `updated_at` is null until the first edit, so COALESCE is
   * what expresses it.
   *
   * ── Why the COALESCE is in the SELECT and not in the ORDER BY ─────────────
   * Writing `->orderBy('COALESCE(r.updatedAt, r.createdAt)', 'DESC')` is the
   * obvious spelling and it does not work:
   *
   *     [Syntax Error] Expected known function, got 'COALESCE'
   *
   * DQL is not SQL. Its ORDER BY clause takes an arithmetic expression or a
   * *result variable*, and any function appearing there must be one Doctrine has
   * registered — COALESCE is not, because it is parsed as a special form (like
   * CASE and NULLIF) rather than as a function. The SELECT clause DOES accept
   * that special form, so the expression goes there and the ORDER BY refers to
   * the alias it creates. Same technique findTrialGroups() uses when it orders
   * by `lastQueuedAt`.
   *
   * `AS HIDDEN` is what keeps this from being a breaking change to the return
   * type. Without it, selecting an extra expression alongside `r` switches
   * hydration from "a list of EvaluationReview objects" to "a list of arrays
   * shaped [0 => EvaluationReview, 'recordedAt' => string]", and every template
   * reading `review.verdict` would break. HIDDEN means "compute it, order by it,
   * then throw it away before hydration" — the sort happens in the database and
   * the caller still gets plain entities.
   *
   * ── Why the joins are explicit ─────────────────────────────────────────────
   * Every row rendered prints its reviewer's name and its reason's label. Left
   * to itself Doctrine would lazy-load both per row: with ten reviews that is
   * twenty extra queries to draw one list. addSelect() on a LEFT JOIN fetches
   * them in the same round trip. LEFT and not INNER for the reason, because a
   * review that agrees has no reason and an INNER JOIN would silently drop every
   * agreeing review from the list — the classic way this optimisation goes
   * wrong.
   *
   * @return EvaluationReview[]
   */
  public function findForEvaluation(Evaluation $evaluation): array {
    return $this->createQueryBuilder('r')
      ->addSelect('reviewer', 'question')
      ->addSelect('COALESCE(r.updatedAt, r.createdAt) AS HIDDEN recordedAt')
      ->leftJoin('r.reviewer', 'reviewer')
      ->leftJoin('r.question', 'question')
      ->andWhere('r.evaluation = :evaluation')
      ->setParameter('evaluation', $evaluation)
      ->orderBy('recordedAt', 'DESC')
      // Total order: without a unique final key two reviews saved in the same
      // second could swap places between renders.
      ->addOrderBy('r.id', 'DESC')
      ->getQuery()
      ->getResult();
  }

  /**
   * The headline numbers for the banner at the top of an evaluation.
   *
   * Returns a plain array, always fully populated:
   *   ['agree' => int, 'disagree' => int, 'total' => int]
   *
   * Counted in SQL rather than by taking findForEvaluation() and filtering it in
   * PHP, because the banner is drawn on every view of the page while the comment
   * list is not always needed — and a GROUP BY over an indexed foreign key is
   * cheaper than hydrating every review, its reviewer and its reason just to
   * count to three. It also means the banner cannot disagree with itself if the
   * list is ever paginated.
   *
   * ── The defensive normalisation below ──────────────────────────────────────
   * A DQL scalar select of an enum-typed field is one of the places where what
   * comes back depends on the Doctrine version: sometimes the backed enum,
   * sometimes the raw backing string the driver handed over. Rather than pin the
   * behaviour, the loop accepts both. This is the same instinct as
   * Evaluation::countAttributesWithStatus() — a surprising value should cost you
   * a number, not the page it appears on.
   *
   * @return array{agree: int, disagree: int, total: int}
   */
  public function tallyFor(Evaluation $evaluation): array {
    $rows = $this->createQueryBuilder('r')
      ->select('r.verdict AS verdict', 'COUNT(r.id) AS total')
      ->andWhere('r.evaluation = :evaluation')
      ->setParameter('evaluation', $evaluation)
      ->groupBy('r.verdict')
      ->getQuery()
      ->getResult();

    // Seeded with both cases at zero so callers never have to test for a missing
    // key: "0 disagree" is a fact worth stating, and an absent key is not.
    $tally = [
      ReviewVerdict::Agree->value => 0,
      ReviewVerdict::Disagree->value => 0,
    ];

    foreach ($rows as $row) {
      $verdict = $row['verdict'] ?? null;
      $key = $verdict instanceof ReviewVerdict ? $verdict->value : (string) $verdict;

      if (array_key_exists($key, $tally)) {
        $tally[$key] = (int) $row['total'];
      }
    }

    return [
      'agree' => $tally[ReviewVerdict::Agree->value],
      'disagree' => $tally[ReviewVerdict::Disagree->value],
      'total' => $tally[ReviewVerdict::Agree->value] + $tally[ReviewVerdict::Disagree->value],
    ];
  }

  /**
   * One page of every review in the system, newest first — the admin table.
   *
   * Same eager-join reasoning as findForEvaluation(), with the evaluation itself
   * joined too: the admin table links to each evaluation and prints its patient
   * name, so without it the page would issue a query per row for that as well.
   *
   * fetchJoinCollection is false because none of these joins is to-many. That
   * matters: with a to-many join the naive COUNT would count joined rows rather
   * than reviews and the pager would report the wrong total. Here every join is
   * to-one, so the cheap count is also the correct one.
   *
   * The HIDDEN alias trick is the same one findForEvaluation() uses, and for the
   * same reason — DQL will not take COALESCE in an ORDER BY. It is written out
   * in full there.
   */
  public function paginateAll(int $page, int $perPage = 50): Paginator {
    $page = max(1, $page);

    $query = $this->createQueryBuilder('r')
      ->addSelect('reviewer', 'question', 'evaluation')
      ->addSelect('COALESCE(r.updatedAt, r.createdAt) AS HIDDEN recordedAt')
      ->leftJoin('r.reviewer', 'reviewer')
      ->leftJoin('r.question', 'question')
      ->leftJoin('r.evaluation', 'evaluation')
      ->orderBy('recordedAt', 'DESC')
      ->addOrderBy('r.id', 'DESC')
      ->setFirstResult(($page - 1) * $perPage)
      ->setMaxResults($perPage)
      ->getQuery();

    return new Paginator($query, fetchJoinCollection: false);
  }

  /**
   * How many reviews cite a given reason.
   *
   * Used by the admin reasons screen to say "used by 4 reviews" next to each
   * one, which is the number that makes "deactivate" versus "this is dead
   * weight" an informed decision.
   *
   * Returned keyed by question id so the template can look up a row's count in
   * O(1) instead of the screen running one COUNT per reason.
   *
   * @return array<int, int> question id => number of reviews citing it
   */
  public function countByQuestion(): array {
    $rows = $this->createQueryBuilder('r')
      ->select('IDENTITY(r.question) AS questionId', 'COUNT(r.id) AS total')
      ->andWhere('r.question IS NOT NULL')
      ->groupBy('r.question')
      ->getQuery()
      ->getResult();

    $counts = [];
    foreach ($rows as $row) {
      $counts[(int) $row['questionId']] = (int) $row['total'];
    }

    return $counts;
  }
}

<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Controller;

use Pixiekat\LuminaUiBundle\Entity\Evaluation;
use Pixiekat\LuminaUiBundle\Interfaces as PixieInterfaces;
use Pixiekat\LuminaUiBundle\ReadModel\TrialEvaluationGroup;
use Pixiekat\LuminaUiBundle\Repository\EvaluationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The trial-centric view of the evaluation data.
 *
 * EvaluationController answers "what have we run, most recently first?". This
 * one answers the other question people actually arrive with: "how did trial X
 * do across our patients?" Same rows underneath — a different axis through them.
 *
 * Two screens:
 *
 *   /trials          → one row per trial, with a run count and a last-run date
 *   /trials/{id}     → every evaluation for that trial, best match first
 *
 * Both are read-only GETs. No forms, no CSRF, nothing to queue: this is a
 * reporting surface, and keeping it strictly read-only is what lets it be linked
 * and bookmarked freely.
 *
 * Kept as its OWN controller rather than four more methods on EvaluationController
 * (already ~475 lines) — routes are discovered by attribute scan across the whole
 * Controller/ directory, so splitting costs nothing in wiring and buys a file you
 * can read top to bottom.
 */
#[IsGranted(PixieInterfaces\Security\Voter\GenericVoterInterface::CAN_ACCESS_EVALUATIONS)]
class TrialController extends AbstractController {

  /**
   * Rows per page. Matches the other listings so the whole UI paginates alike.
   */
  private const int PER_PAGE = 25;

  public function __construct(
    private readonly EvaluationRepository $evaluations,
    private readonly PixieInterfaces\Service\TrialsManagerInterface $trialManager,
  ) {}

  /**
   * Trials that have evaluations, grouped: number, name, run count, last run.
   *
   * ── Two queries, not two-per-row ───────────────────────────────────────────
   * The naive shape of this page is a loop that calls $trialManager->find($id)
   * for each group — the classic N+1, and a particularly expensive one here
   * because every call crosses into a second database. Instead:
   *
   *   1. ONE aggregate query gets every group (and therefore every trial id).
   *   2. ONE findMany() resolves all of those ids in a single IN (...) lookup.
   *   3. The two are stitched together in PHP into TrialEvaluationGroup rows.
   *
   * Cost is flat in the number of rows. This "collect ids → batch fetch → zip"
   * pattern is the general answer whenever a listing needs data from a second
   * system; worth recognising, because it comes up constantly.
   */
  #[Route('/', name: 'lumina_ui_trial_index_home', methods: ['GET'])]
  #[Route('/trials', name: 'lumina_ui_trial_index', methods: ['GET'])]
  public function index(Request $request): Response {
    $rows = $this->evaluations->findTrialGroups();
    $total = count($rows);
    $pages = max(1, (int) ceil($total / self::PER_PAGE));

    // Clamp AFTER knowing the total, so ?page=999 lands on the last page rather
    // than an empty table that gives no clue what happened. Same reasoning as
    // EvaluationController::trialPickerData().
    $page = min(max(1, $request->query->getInt('page', 1)), $pages);

    // Slice FIRST, then resolve trials — there is no point asking exact_db about
    // 400 trials to render 25 of them.
    $pageRows = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

    // findMany() returns an array keyed by trial id, which is exactly the lookup
    // table the zip below wants. Missing ids simply do not appear — the read
    // model treats a null trial as a normal, displayable outcome.
    $trials = $this->trialManager->findMany(array_column($pageRows, 'trialId'));

    $groups = array_map(
      static fn(array $row): TrialEvaluationGroup => TrialEvaluationGroup::fromAggregateRow(
        $row,
        $trials[(int) $row['trialId']] ?? null,
      ),
      $pageRows,
    );

    return $this->render('@LuminaUi/trial/index.html.twig', [
      'groups' => $groups,
      'page' => $page,
      'pages' => $pages,
      'total' => $total,
    ]);
  }

  /**
   * Every evaluation for one trial, ranked by how many attributes matched.
   *
   * ── Why the sort happens in PHP ────────────────────────────────────────────
   * "Matches" is a count of entries inside the schemaless `attributes` JSON
   * column, so there is no column to ORDER BY and no portable SQL expression that
   * produces one. Fetching the trial's rows and sorting them here is honest about
   * that; the alternative and the row count that would justify it are written up
   * on EvaluationRepository::findByTrial().
   *
   * The consequence to be aware of: pagination is applied to the sorted array
   * rather than pushed into the query, so the whole set for this trial is
   * materialised on every request. Bounded by (patients × re-runs) for one trial,
   * which is a few hundred rows here.
   */
  #[Route('/trials/{trialId}', name: 'lumina_ui_trial_show', methods: ['GET'], requirements: ['trialId' => '\d+'])]
  public function show(int $trialId, Request $request): Response {
    $evaluations = $this->evaluations->findByTrial($trialId);

    if ($evaluations === []) {
      // A trial with no evaluations has nothing to show on THIS screen, whether
      // or not it exists in exact_db. 404 rather than an empty table, so a
      // mistyped id is unambiguous.
      throw $this->createNotFoundException(sprintf('No evaluations recorded for trial #%d.', $trialId));
    }

    // usort() is not stable across equal keys in the way we want, so the
    // comparator resolves ties explicitly rather than leaving them to chance:
    // most matches first, then fewest unknowns (a 5-match result built on solid
    // data outranks one where half the attributes were unreadable), then newest.
    // A deterministic total order is not a nicety — without it, two rows with
    // equal match counts could swap places between page 1 and page 2 and a row
    // would vanish from the pager entirely.
    usort($evaluations, static function (Evaluation $a, Evaluation $b): int {
      return $b->getMatchedCount() <=> $a->getMatchedCount()
        ?: $a->getUnknownCount() <=> $b->getUnknownCount()
        ?: $b->getId() <=> $a->getId();
    });

    $total = count($evaluations);
    $pages = max(1, (int) ceil($total / self::PER_PAGE));
    $page = min(max(1, $request->query->getInt('page', 1)), $pages);

    // The trial record is decoration on this page, not a precondition: the
    // evaluations are ours and are readable even when exact_db is not. Null here
    // means the template shows the id and a short note instead of a title.
    $trial = $this->trialManager->find($trialId);

    return $this->render('@LuminaUi/trial/show.html.twig', [
      'trialId' => $trialId,
      'trial' => $trial,
      'evaluations' => array_slice($evaluations, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
      'page' => $page,
      'pages' => $pages,
      'total' => $total,
    ]);
  }
}

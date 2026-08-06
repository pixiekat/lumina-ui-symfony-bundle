<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Controller;

use Pixiekat\LuminaUiBundle\Entity\Evaluation;
use Pixiekat\LuminaUiBundle\Interfaces as PixieInterfaces;
use Pixiekat\LuminaUiBundle\ReadModel\PatientEvaluationGroup;
use Pixiekat\LuminaUiBundle\Repository\EvaluationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The patient-centric view of the evaluation data.
 *
 * The third axis through the same table, and the direct counterpart of
 * TrialController:
 *
 *   EvaluationController → "what have we run lately?"        (chronological)
 *   TrialController      → "how did trial X do?"             (by trial)
 *   PatientController    → "what do we know about patient Y?" (by patient)
 *
 * Two screens, both read-only GETs:
 *
 *   /patients        → one row per patient, with a run count and a last-run date
 *   /patients/{id}   → every run for that patient, best match first
 *
 * ── The one asymmetry with the trial screens ───────────────────────────────
 * A patient's runs can include BOTH command kinds: per-trial `explain_trial_match`
 * rows AND `search_trials_for_patients` rows, which have no single trial and no
 * attribute breakdown. The detail table renders both and labels the difference
 * rather than hiding it — see the template, and findPatientGroups() for why the
 * grouping query has no null-guard where the trial one does.
 */
#[IsGranted(PixieInterfaces\Security\Voter\GenericVoterInterface::CAN_ACCESS_EVALUATIONS)]
class PatientController extends AbstractController {

  /** Rows per page. Matches the other listings so the whole UI paginates alike. */
  private const int PER_PAGE = 25;

  public function __construct(
    private readonly EvaluationRepository $evaluations,
    private readonly PixieInterfaces\Service\PatientManagerInterface $patientManager,
    private readonly PixieInterfaces\Service\TrialsManagerInterface $trialManager,
  ) {}

  /**
   * Patients that have evaluations, grouped: id, name, run count, last run.
   *
   * Same "collect ids → batch fetch → zip" shape as TrialController::index(), for
   * the same reason: the naive version calls $patientManager->find() once per row,
   * which is an N+1 that crosses into a SECOND DATABASE on every iteration. Two
   * queries total here, regardless of how many rows come back.
   *
   * Worth noting that the name genuinely has to be fetched rather than read off
   * the evaluation row — Evaluation::$patientName is encrypted at rest, so the
   * GROUP BY cannot carry it. PatientEvaluationGroup's docblock has the details.
   */
  #[Route('/patients', name: 'lumina_ui_patient_index', methods: ['GET'])]
  public function index(Request $request): Response {
    $rows = $this->evaluations->findPatientGroups();
    $total = count($rows);
    $pages = max(1, (int) ceil($total / self::PER_PAGE));

    // Clamp AFTER knowing the total, so ?page=999 lands on the last page rather
    // than an empty table that gives no clue what happened.
    $page = min(max(1, $request->query->getInt('page', 1)), $pages);

    // Slice FIRST, then resolve patients — no point asking ctomop about 107
    // people to render 25 of them.
    $pageRows = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

    // findMany() returns an array keyed by person_id, which is exactly the lookup
    // table the zip below wants. Ids ctomop no longer knows about are simply
    // absent — the read model treats a null patient as a displayable outcome.
    $patients = $this->patientManager->findMany(array_column($pageRows, 'personId'));

    $groups = array_map(
      static fn(array $row): PatientEvaluationGroup => PatientEvaluationGroup::fromAggregateRow(
        $row,
        $patients[(int) $row['personId']] ?? null,
      ),
      $pageRows,
    );

    return $this->render('@LuminaUi/patient/index.html.twig', [
      'groups' => $groups,
      'page' => $page,
      'pages' => $pages,
      'total' => $total,
    ]);
  }

  /**
   * Every run for one patient, ranked by how many attributes matched.
   *
   * The sort is Evaluation::compareByMatchQuality() — the same rule the per-trial
   * table uses, defined once on the entity. It happens in PHP because "matches"
   * are counted inside the schemaless `attributes` JSON column; the reasoning and
   * the Postgres-native alternative are on EvaluationRepository::findByTrial().
   *
   * ── A note on how search rows rank ─────────────────────────────────────────
   * `search_trials_for_patients` rows have no attribute breakdown, so they score
   * zero matches and settle at the bottom. That is the honest placement rather
   * than a bug — they are a different measurement (best-match percentage across
   * ALL trials), not a zero-scoring version of the same one. The template says so
   * in the row instead of leaving a column of em dashes to be misread.
   */
  #[Route('/patients/{personId}', name: 'lumina_ui_patient_show', methods: ['GET'], requirements: ['personId' => '\d+'])]
  public function show(int $personId, Request $request): Response {
    $evaluations = $this->evaluations->findByPatient($personId);

    if ($evaluations === []) {
      // Nothing to show on THIS screen whether or not ctomop knows the person.
      // A 404 rather than an empty table, so a mistyped id is unambiguous.
      throw $this->createNotFoundException(sprintf('No evaluations recorded for patient #%d.', $personId));
    }

    usort($evaluations, Evaluation::compareByMatchQuality(...));

    $total = count($evaluations);
    $pages = max(1, (int) ceil($total / self::PER_PAGE));
    $page = min(max(1, $request->query->getInt('page', 1)), $pages);
    $pageRows = array_slice($evaluations, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

    // The same batching discipline again, one level down: this table has a Trial
    // column, so resolve every trial on THIS PAGE in one lookup rather than
    // letting the template call find() per row. array_filter drops the nulls that
    // search-kind rows contribute before they reach the query.
    $trials = $this->trialManager->findMany(
      array_filter(array_map(static fn(Evaluation $e): ?int => $e->getTrialId(), $pageRows)),
    );

    // Decoration, not a precondition: the runs are ours and stay readable even
    // when ctomop is not. Null means the template shows the id and a short note.
    $patient = $this->patientManager->find($personId);

    return $this->render('@LuminaUi/patient/show.html.twig', [
      'personId' => $personId,
      'patient' => $patient,
      'evaluations' => $pageRows,
      'trials' => $trials,
      'page' => $page,
      'pages' => $pages,
      'total' => $total,
    ]);
  }
}

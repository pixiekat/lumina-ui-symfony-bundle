<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\LuminaUiBundle\Entity\Evaluation;
use Pixiekat\LuminaUiBundle\Entity\EvaluationBatch;
use Pixiekat\LuminaUiBundle\Enum\EvaluationKind;
use Pixiekat\LuminaUiBundle\Enum\EvaluationStatus;
use Pixiekat\LuminaUiBundle\Enum\MatchingSoftware;
use Pixiekat\LuminaUiBundle\Interfaces as PixieInterfaces;
use Pixiekat\LuminaUiBundle\Message\RunBatch;
use Pixiekat\LuminaUiBundle\Message\RunEvaluation;
use Pixiekat\LuminaUiBundle\Repository\EvaluationBatchRepository;
use Pixiekat\LuminaUiBundle\Repository\EvaluationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Lumina UI screens for evaluations and batches.
 *
 * Read paths (index/show/json) render existing rows. Write paths (new/rerun/
 * run-all) are POST-only, CSRF-protected, and merely *queue* work: they create
 * a Pending row/batch, dispatch a Messenger message, and redirect. A worker
 * (`bin/console messenger:consume async`) does the actual `docker exec`.
 *
 * Forms are plain HTML POSTs so everything works with JavaScript disabled;
 * Turbo, if loaded, progressively enhances navigation without being required.
 */
#[IsGranted(PixieInterfaces\Security\Voter\GenericVoterInterface::CAN_ACCESS_EVALUATIONS)]
class EvaluationController extends AbstractController {

  public function __construct(
    private readonly EvaluationRepository $evaluations,
    private readonly EvaluationBatchRepository $batches,
    private readonly EntityManagerInterface $em,
    private readonly MessageBusInterface $bus,
    private readonly PixieInterfaces\Service\PatientManagerInterface $patientManager,
    private readonly PixieInterfaces\Service\TrialsManagerInterface $trialManager,
  ) {}

  /** The landing page: a paginated table of standalone evaluations. */
  #[Route('/evaluations', name: 'lumina_ui_evaluation_index', methods: ['GET'])]
  public function index(Request $request): Response {
    $perPage = 25;
    $page = max(1, $request->query->getInt('page', 1));
    $paginator = $this->evaluations->paginateStandalone($page, $perPage);
    $total = count($paginator);

    return $this->render('@LuminaUi/evaluation/index.html.twig', [
      'evaluations' => $paginator,
      'page' => $page,
      'pages' => max(1, (int) ceil($total / $perPage)),
      'total' => $total,
    ]);
  }

  /** Deletes an evaluation. */
  #[Route('/evaluations/{id}', name: 'lumina_ui_evaluation_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function delete(int $id, Request $request): Response {
    $evaluation = $this->findEvaluationOr404($id);

    if (!$this->isCsrfTokenValid('delete-evaluation', (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('lumina_ui_evaluation_show', ['id' => $evaluation->getId()]);
    }

    $this->em->remove($evaluation);
    $this->em->flush();

    $this->addFlash('success', sprintf('Evaluation #%d deleted.', $evaluation->getId()));

    return $this->redirectToRoute('lumina_ui_evaluation_index');
  }

  /**
   * Creates a new evaluation for the patient; the first stage is a trial picker, the second stage is a patient queue.
   *
   * @return Response
   * @throws \Doctrine\DBAL\Exception
   */
  #[Route('/evaluations/new', name: 'lumina_ui_evaluation_new', methods: ['GET'])]
  public function new(Request $request): Response {
    // get trial from the query string, if any.
    $trialId = $request->query->getInt('trial');

    // ── Step 1: no trial chosen, so show the list of trials.
    if ($trialId <= 0) {
      return $this->render('@LuminaUi/evaluation/new.html.twig', $this->trialPickerData($request));
    }

    // ── Step 2: we have a trial, so find the trial by id.
    $trial = $this->trialManager->find($trialId);

    if ($trial === null) {
      // no trial found, so redirect back to the trial picker with an error message.
      $this->addFlash('error', sprintf(
        'Trial #%d was not found in the trials database. It may have been removed, or this Lumina is pointed at a different trials database.',
        $trialId,
      ));

      return $this->redirectToRoute('lumina_ui_evaluation_new');
    }

    // return the twig template with the list of patients.
    return $this->render('@LuminaUi/evaluation/new.html.twig', [
      'trial' => $trial,
    ] + $this->patientQueueData($trial->id));
  }

  /**
   * The table of patients, for the status poller to refresh.
   *
   */
  #[Route('/evaluations/new/rows', name: 'lumina_ui_evaluation_new_rows', methods: ['GET'])]
  public function newRows(Request $request): Response {
    $trialId = $request->query->getInt('trial');
    $trial = $trialId > 0 ? $this->trialManager->find($trialId) : null;

    if ($trial === null) {
      // No flash-and-redirect here: nothing is watching for one. A poller asking
      // about a trial that has gone away should get a plain 404 and stop, which
      // is exactly what the controller's error branch does with it.
      throw $this->createNotFoundException(sprintf('Trial #%d not found.', $trialId));
    }

    return $this->render('@LuminaUi/evaluation/_patient_rows_frame.html.twig', [
      'trial' => $trial,
    ] + $this->patientQueueData($trial->id));
  }

  /**
   * Queues a patient for a trial.
   *
   * @return Response
   * @throws \Doctrine\DBAL\Exception
   */
  #[Route('/evaluations/queue', name: 'lumina_ui_evaluation_queue', methods: ['POST'])]
  public function queue(Request $request): Response {
    $trialId = (int) $request->request->get('trial_id');
    $personId = (int) $request->request->get('person_id');

    // is the csrf token valid? If not, redirect back to the trial picker with an error message.
    if (!$this->isCsrfTokenValid('queue-' . $trialId . '-' . $personId, (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');

      return $this->redirectToNewFor($trialId, $personId);
    }

    if ($trialId <= 0 || $personId <= 0) {
      $this->addFlash('error', 'A trial and a patient must both be selected.');

      return $this->redirectToRoute('lumina_ui_evaluation_new');
    }

    // checks to see if an evaluation already exists for this trial and person.
    // show a flash message and redirect back to the trial picker with an error message if it does.
    $active = $this->evaluations->findActiveFor($trialId, $personId);
    if ($active !== null) {
      $this->addFlash('info', sprintf(
        'Evaluation #%d for this patient is already %s.',
        $active->getId(),
        $active->getStatus()->label(),
      ));

      return $this->redirectToNewFor($trialId, $personId);
    }

    $evaluation = new Evaluation(MatchingSoftware::Exact, EvaluationKind::ExplainTrialMatch, $personId);
    $evaluation->setTrialId($trialId);

    // gets the proper patient name from ctomop for the evaluation row.
    $patient = $this->patientManager->find($personId);
    if ($patient !== null) {
      $evaluation->setPatientName($patient->displayName());
    }

    try {
      $this->em->persist($evaluation);
      $this->em->flush();
    }
    catch (UniqueConstraintViolationException) {
      // edge case where two requests try to queue the same patient at the same time. The first one wins, the second one gets this exception. We don't want to throw a 500 error, so we just show a flash message and redirect back to the trial picker with an error message.
      $this->addFlash('info', 'That evaluation was queued a moment ago by another request.');

      return $this->redirectToNewFor($trialId, $personId);
    }

    $this->bus->dispatch(new RunEvaluation($evaluation->getId()));

    $this->addFlash('success', sprintf(
      'Queued evaluation #%d for %s — it will run in the background.',
      $evaluation->getId(),
      $patient?->displayName() ?? ('patient ' . $personId),
    ));

    return $this->redirectToNewFor($trialId, $personId);
  }

  /**
   * Queue every patient who has NO evaluation yet against this trial.
   */
  #[Route('/evaluations/queue-all', name: 'lumina_ui_evaluation_queue_all', methods: ['POST'])]
  public function queueAll(Request $request): Response {
    $trialId = (int) $request->request->get('trial_id');

    if (!$this->isCsrfTokenValid('queue-all-' . $trialId, (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');

      return $this->redirectToNewFor($trialId, 0);
    }

    $trial = $trialId > 0 ? $this->trialManager->find($trialId) : null;
    if ($trial === null) {
      $this->addFlash('error', 'That trial was not found in the trials database.');

      return $this->redirectToRoute('lumina_ui_evaluation_new');
    }

    $latest = $this->evaluations->findLatestByTrialIndexedByPerson($trial->id);
    $remaining = array_filter(
      $this->patientManager->findAll(),
      static fn(int $personId): bool => !isset($latest[$personId]),
      \ARRAY_FILTER_USE_KEY,
    );

    if ($remaining === []) {
      $this->addFlash('info', 'Every patient already has an evaluation for this trial.');

      return $this->redirectToNewFor($trial->id, 0);
    }

    $batch = new EvaluationBatch(MatchingSoftware::Exact, EvaluationKind::ExplainTrialMatch);
    $batch->setLabel(sprintf(
      'Queue all — %s (trial %d)',
      $trial->studyId ?? $trial->displayTitle(),
      $trial->id,
    ));
    $this->em->persist($batch);

    $queued = [];
    foreach ($remaining as $personId => $patient) {
      $evaluation = new Evaluation(MatchingSoftware::Exact, EvaluationKind::ExplainTrialMatch, $personId);
      $evaluation
        ->setTrialId($trial->id)
        ->setPatientName($patient->displayName());
      // Sets both sides of the relation.
      $batch->addEvaluation($evaluation);
      $this->em->persist($evaluation);
      $queued[] = $evaluation;
    }

    try {
      // persist the batch and all its evaluations in one transaction. If any of the evaluations already exist, the whole transaction will fail and we will catch the exception below.
      $this->em->flush();
    }
    catch (UniqueConstraintViolationException) {
      // catch if someone queued the same patient at the same time to protect against 500 errors.
      $this->addFlash('error', 'Someone queued one of these patients at the same time — nothing was queued. Please try again.');

      return $this->redirectToNewFor($trial->id, 0);
    }

    // Dispatch only after the flush, so every row has a real id. Each message is
    // an independent RunEvaluation, which means the existing handler is reused
    // untouched and every row reports its own status as it goes — which is what
    // makes the table fill in progressively rather than all at once at the end.
    foreach ($queued as $evaluation) {
      $this->bus->dispatch(new RunEvaluation($evaluation->getId()));
    }

    $this->addFlash('success', sprintf(
      'Queued %d evaluation%s as batch #%d — they will run in the background.',
      count($queued),
      count($queued) === 1 ? '' : 's',
      $batch->getId(),
    ));

    return $this->redirectToNewFor($trial->id, 0);
  }

  /** Detail view: readable attribute table + raw output. */
  #[Route('/evaluations/{id}', name: 'lumina_ui_evaluation_show', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function show(int $id): Response {
    return $this->render('@LuminaUi/evaluation/show.html.twig', [
      'evaluation' => $this->findEvaluationOr404($id),
    ]);
  }

  /** The explain breakdown as raw, pretty-printed JSON. */
  #[Route('/evaluations/{id}/json', name: 'lumina_ui_evaluation_json', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function viewJson(int $id): JsonResponse {
    $evaluation = $this->findEvaluationOr404($id);

    $response = new JsonResponse([
      'id'         => $evaluation->getId(),
      'software'   => $evaluation->getSoftware()->value,
      'kind'       => $evaluation->getKind()->value,
      'personId'   => $evaluation->getPersonId(),
      'trialId'    => $evaluation->getTrialId(),
      'status'     => $evaluation->getStatus()->value,
      'ran_at'     => $evaluation->getRanAt() ? $evaluation->getRanAt()->format(\DateTimeInterface::ATOM) : null,
      'created_at' => $evaluation->getCreatedAt() ? $evaluation->getCreatedAt()->format(\DateTimeInterface::ATOM) : null,
      'updated_at' => $evaluation->getUpdatedAt() ? $evaluation->getUpdatedAt()->format(\DateTimeInterface::ATOM) : null,
      'summary'    => $evaluation->getSummary(),
      'attributes' => $evaluation->getAttributes(),
    ]);
    // Make it pleasant to read in a browser tab.
    $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

    return $response;
  }

  /** Re-run: clone an existing evaluation into a fresh Pending row and queue it. */
  #[Route('/evaluations/{id}/rerun', name: 'lumina_ui_evaluation_rerun', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function rerun(int $id, Request $request): Response {
    $original = $this->findEvaluationOr404($id);

    if (!$this->isCsrfTokenValid('rerun-' . $id, (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('lumina_ui_evaluation_show', ['id' => $id]);
    }

    // A re-run is a NEW row so history is preserved.
    $clone = new Evaluation($original->getSoftware(), $original->getKind(), $original->getPersonId());
    $clone
      ->setTrialId($original->getTrialId())
      ->setDisease($original->getDisease())
      ->setPatientName($original->getPatientName());
    $this->em->persist($clone);
    $this->em->flush();

    $this->bus->dispatch(new RunEvaluation($clone->getId()));
    $this->addFlash('success', sprintf('Re-running as evaluation #%d.', $clone->getId()));

    return $this->redirectToRoute('lumina_ui_evaluation_show', ['id' => $clone->getId()]);
  }

  /** Run all: queue a batch search across every patient. */
  #[Route('/evaluations/run-all', name: 'lumina_ui_evaluation_run_all', methods: ['POST'])]
  public function runAll(Request $request): Response {
    if (!$this->isCsrfTokenValid('run-all', (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('lumina_ui_evaluation_index');
    }

    $batch = new EvaluationBatch(MatchingSoftware::Exact, EvaluationKind::SearchTrials);
    $batch->setLabel('Run all — search_trials_for_patients');
    $this->em->persist($batch);
    $this->em->flush();

    $this->bus->dispatch(new RunBatch($batch->getId()));
    $this->addFlash('success', sprintf('Queued batch #%d — searching trials for all patients.', $batch->getId()));

    return $this->redirectToRoute('lumina_ui_batch_show', ['id' => $batch->getId()]);
  }

  /** A table of batch runs and their statuses. */
  #[Route('/batches', name: 'lumina_ui_batch_index', methods: ['GET'])]
  public function batchIndex(): Response {
    return $this->render('@LuminaUi/batch/index.html.twig', [
      'batches' => $this->batches->findLatest(),
    ]);
  }

  /** Batch detail: rollup + its child evaluations. */
  #[Route('/batches/{id}', name: 'lumina_ui_batch_show', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function batchShow(int $id): Response {
    $batch = $this->batches->find($id);
    if ($batch === null) {
      throw $this->createNotFoundException(sprintf('Batch #%d not found.', $id));
    }

    return $this->render('@LuminaUi/batch/show.html.twig', [
      'batch' => $batch,
    ]);
  }

  private function findEvaluationOr404(int $id): Evaluation {
    $evaluation = $this->evaluations->find($id);
    if ($evaluation === null) {
      throw $this->createNotFoundException(sprintf('Evaluation #%d not found.', $id));
    }
    return $evaluation;
  }

  /**
   * Stage 1 view data: one page of trials to choose from.
   *
   * @return array<string, mixed>
   */
  private function trialPickerData(Request $request): array {
    $perPage = 25;
    $page = max(1, $request->query->getInt('page', 1));

    // findAll() is keyed by trial id; array_values gives us a list to slice.
    $all = array_values($this->trialManager->findAll());
    $total = count($all);
    $pages = max(1, (int) ceil($total / $perPage));

    // Clamp AFTER knowing the total, so ?page=999 shows the last page rather
    // than an empty table with no way to tell what happened.
    $page = min($page, $pages);

    return [
      'trial' => null,
      'trials' => array_slice($all, ($page - 1) * $perPage, $perPage),
      'page' => $page,
      'pages' => $pages,
      'total' => $total,
    ];
  }

  /**
   * Stage 2 view data: every patient, paired with its latest evaluation against
   * this trial.
   *
   * @return array<string, mixed>
   */
  private function patientQueueData(int $trialId): array {
    $patients = $this->patientManager->findAll();
    $latest = $this->evaluations->findLatestByTrialIndexedByPerson($trialId);

    // Counts for the summary line above the table. Also the seed for the
    // aria-live region, so screen reader users get one useful sentence rather
    // than a hundred silently-changing cells.
    $counts = ['queued' => 0, 'active' => 0, 'completed' => 0, 'failed' => 0];
    foreach ($latest as $evaluation) {
      $counts['queued']++;
      match ($evaluation->getStatus()) {
        EvaluationStatus::Completed => $counts['completed']++,
        EvaluationStatus::Failed => $counts['failed']++,
        default => $counts['active']++,
      };
    }

    // Patients with no evaluation at all for this trial — the population the
    // "Queue all" button acts on. Terminal rows are excluded on purpose: those
    // would be re-runs, which stay a per-row decision.
    $remaining = 0;
    foreach ($patients as $personId => $patient) {
      if (!isset($latest[$personId])) {
        $remaining++;
      }
    }

    return [
      'patients' => $patients,
      'latest' => $latest,
      'counts' => $counts,
      'remaining' => $remaining,
      'hasActive' => $counts['active'] > 0,
    ];
  }

  /**
   * Back to the patient list for a trial, focused on the row just acted upon.
   */
  private function redirectToNewFor(int $trialId, int $personId): Response {
    if ($trialId <= 0) {
      return $this->redirectToRoute('lumina_ui_evaluation_new');
    }

    $url = $this->generateUrl('lumina_ui_evaluation_new', ['trial' => $trialId]);

    return $this->redirect($personId > 0 ? $url . '#patient-' . $personId : $url);
  }
}

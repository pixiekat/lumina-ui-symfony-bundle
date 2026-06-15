<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\LuminaUiBundle\Entity\Evaluation;
use Pixiekat\LuminaUiBundle\Entity\EvaluationBatch;
use Pixiekat\LuminaUiBundle\Enum\EvaluationKind;
use Pixiekat\LuminaUiBundle\Enum\MatchingSoftware;
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
class EvaluationController extends AbstractController {

  public function __construct(
    private readonly EvaluationRepository $evaluations,
    private readonly EvaluationBatchRepository $batches,
    private readonly EntityManagerInterface $em,
    private readonly MessageBusInterface $bus,
  ) {}

  /** The landing page: a paginated table of standalone evaluations. */
  #[Route('/', name: 'lumina_ui_evaluation_index', methods: ['GET'])]
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

  /** Create a one-off explain_trial_match evaluation (person × trial). */
  #[Route('/evaluations/new', name: 'lumina_ui_evaluation_new', methods: ['GET', 'POST'])]
  public function new(Request $request): Response {
    if ($request->isMethod('POST')) {
      if (!$this->isCsrfTokenValid('new-evaluation', (string) $request->request->get('_token'))) {
        $this->addFlash('error', 'Invalid security token — please try again.');
        return $this->redirectToRoute('lumina_ui_evaluation_new');
      }

      $personId = (int) $request->request->get('person_id');
      $trialId = (int) $request->request->get('trial_id');
      if ($personId <= 0 || $trialId <= 0) {
        $this->addFlash('error', 'Person ID and Trial ID must both be positive numbers.');
        return $this->redirectToRoute('lumina_ui_evaluation_new');
      }

      $evaluation = new Evaluation(MatchingSoftware::Exact, EvaluationKind::ExplainTrialMatch, $personId);
      $evaluation->setTrialId($trialId);
      $this->em->persist($evaluation);
      $this->em->flush();

      $this->bus->dispatch(new RunEvaluation($evaluation->getId()));
      $this->addFlash('success', sprintf('Queued evaluation #%d — it will run in the background.', $evaluation->getId()));

      return $this->redirectToRoute('lumina_ui_evaluation_show', ['id' => $evaluation->getId()]);
    }

    return $this->render('@LuminaUi/evaluation/new.html.twig');
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
}

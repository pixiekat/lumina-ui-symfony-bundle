<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\LuminaUiBundle\Entity\Evaluation;
use Pixiekat\LuminaUiBundle\Enum\EvaluationKind;
use Pixiekat\LuminaUiBundle\Enum\EvaluationStatus;
use Pixiekat\LuminaUiBundle\Message\RunBatch;
use Pixiekat\LuminaUiBundle\Repository\EvaluationBatchRepository;
use Pixiekat\LuminaUiBundle\Service\ExactCommandRunner;
use Pixiekat\LuminaUiBundle\Service\OutputParser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs a "run all" batch: executes `search_trials_for_patients` over every
 * patient, then creates one child Evaluation row per parsed patient line and
 * records the batch rollup (footer totals, exit code, timing).
 */
#[AsMessageHandler]
final class RunBatchHandler {

  public function __construct(
    private readonly EvaluationBatchRepository $batches,
    private readonly EntityManagerInterface $em,
    private readonly ExactCommandRunner $runner,
    private readonly OutputParser $parser,
    private readonly LoggerInterface $logger,
  ) {}

  public function __invoke(RunBatch $message): void {
    $batch = $this->batches->find($message->batchId);
    if ($batch === null) {
      $this->logger->warning('RunBatch: batch {id} not found — skipping.', ['id' => $message->batchId]);
      return;
    }

    $batch
      ->setStatus(EvaluationStatus::Running)
      ->setStartedAt(new \DateTimeImmutable());
    $this->em->flush();

    // The whole-cohort run (no --person-ids = all patients).
    $result = $this->runner->run(['search_trials_for_patients']);

    $batch
      ->setCommand($result['command'])
      ->setRawOutput($result['stdout'] !== '' ? $result['stdout'] : $result['stderr'])
      ->setExitCode($result['exitCode'])
      ->setDurationMs($result['durationMs'])
      ->setFinishedAt(new \DateTimeImmutable());

    if ($result['exitCode'] === 0) {
      $parsed = $this->parser->parseSearch($result['stdout']);

      // One Evaluation row per patient line, attached to this batch.
      foreach ($parsed['patients'] as $row) {
        $evaluation = new Evaluation($batch->getSoftware(), EvaluationKind::SearchTrials, $row['personId']);
        $evaluation
          ->setDisease($row['disease'])
          ->setSummary([
            'total'            => $row['total'],
            'eligible'         => $row['eligible'],
            'potential'        => $row['potential'],
            'bestMatchPercent' => $row['bestMatchPercent'],
            'bestGoodness'     => $row['bestGoodness'],
          ])
          ->setStatus(EvaluationStatus::Completed)
          ->setRanAt(new \DateTimeImmutable());
        $batch->addEvaluation($evaluation); // sets both sides of the relation
        $this->em->persist($evaluation);
      }

      $batch
        ->setSummary($parsed['footer'])
        ->setStatus(EvaluationStatus::Completed);
    } else {
      $batch->setStatus(EvaluationStatus::Failed);
      $this->logger->error('RunBatch {id} exited {code}.', [
        'id' => $batch->getId(),
        'code' => $result['exitCode'],
      ]);
    }

    $this->em->flush();
  }
}

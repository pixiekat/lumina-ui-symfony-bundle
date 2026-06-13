<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\LuminaUiBundle\Enum\EvaluationKind;
use Pixiekat\LuminaUiBundle\Enum\EvaluationStatus;
use Pixiekat\LuminaUiBundle\Message\RunEvaluation;
use Pixiekat\LuminaUiBundle\Repository\EvaluationRepository;
use Pixiekat\LuminaUiBundle\Service\ExactCommandRunner;
use Pixiekat\LuminaUiBundle\Service\OutputParser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs a single Evaluation in the background: flips it to Running, shells out to
 * the EXACT container, parses the output, and stores the result. Any failure
 * leaves a Failed row with the captured output for debugging — it never throws
 * past the worker for an ordinary non-zero exit.
 */
#[AsMessageHandler]
final class RunEvaluationHandler {

  public function __construct(
    private readonly EvaluationRepository $evaluations,
    private readonly EntityManagerInterface $em,
    private readonly ExactCommandRunner $runner,
    private readonly OutputParser $parser,
    private readonly LoggerInterface $logger,
  ) {}

  public function __invoke(RunEvaluation $message): void {
    $evaluation = $this->evaluations->find($message->evaluationId);
    if ($evaluation === null) {
      $this->logger->warning('RunEvaluation: evaluation {id} not found — skipping.', ['id' => $message->evaluationId]);
      return;
    }

    // Mark as in-flight so the UI shows "Running".
    $evaluation->setStatus(EvaluationStatus::Running);
    $this->em->flush();

    // Build the argument list for the relevant manage.py subcommand.
    $args = match ($evaluation->getKind()) {
      EvaluationKind::ExplainTrialMatch => [
        'explain_trial_match',
        '--person-id', (string) $evaluation->getPersonId(),
        '--trial-id', (string) $evaluation->getTrialId(),
      ],
      EvaluationKind::SearchTrials => [
        'search_trials_for_patients',
        '--person-ids', (string) $evaluation->getPersonId(),
      ],
    };

    $result = $this->runner->run($args);

    // Record the mechanics of the run regardless of success.
    $evaluation
      ->setCommand($result['command'])
      ->setRawOutput($result['stdout'] !== '' ? $result['stdout'] : $result['stderr'])
      ->setExitCode($result['exitCode'])
      ->setDurationMs($result['durationMs'])
      ->setRanAt(new \DateTimeImmutable());

    if ($result['exitCode'] === 0) {
      $this->applyParsedResult($evaluation, $result['stdout']);
      $evaluation->setStatus(EvaluationStatus::Completed);
    } else {
      $evaluation->setStatus(EvaluationStatus::Failed);
      $this->logger->error('RunEvaluation {id} exited {code}.', [
        'id' => $evaluation->getId(),
        'code' => $result['exitCode'],
      ]);
    }

    $this->em->flush();
  }

  /** Fill the parsed fields based on the command kind. */
  private function applyParsedResult(object $evaluation, string $stdout): void {
    if ($evaluation->getKind() === EvaluationKind::ExplainTrialMatch) {
      $parsed = $this->parser->parseExplain($stdout);
      $evaluation
        ->setPatientName($parsed['patientName'] ?? $evaluation->getPatientName())
        ->setDisease($parsed['disease'] ?? $evaluation->getDisease())
        ->setSummary($parsed['summary'])
        ->setAttributes($parsed['attributes']);
      return;
    }

    // SearchTrials for a single patient — take the first (only) parsed line.
    $parsed = $this->parser->parseSearch($stdout);
    $row = $parsed['patients'][0] ?? null;
    if ($row !== null) {
      $evaluation
        ->setDisease($row['disease'])
        ->setSummary([
          'total'            => $row['total'],
          'eligible'         => $row['eligible'],
          'potential'        => $row['potential'],
          'bestMatchPercent' => $row['bestMatchPercent'],
          'bestGoodness'     => $row['bestGoodness'],
        ]);
    }
  }
}

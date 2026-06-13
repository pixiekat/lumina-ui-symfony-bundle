<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Message;

/**
 * Asks a background worker to (re-)run a single Evaluation row.
 *
 * We pass only the id, not the entity — messages are serialized onto the queue,
 * so the handler reloads a fresh, managed entity from the database.
 */
final readonly class RunEvaluation {
  public function __construct(
    public int $evaluationId,
  ) {}
}

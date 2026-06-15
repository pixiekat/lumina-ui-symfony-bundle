<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Service;

use Symfony\Component\Process\Process;

/**
 * Runs a `manage.py` subcommand inside the EXACT docker container and captures
 * the result. This is the single chokepoint where we shell out — keeping it in
 * one small service makes it easy to mock in tests or swap the transport later
 * (e.g. SSH instead of `docker exec`).
 *
 * The container name / python binary default to the dev compose setup but can
 * be overridden by binding the constructor args in config (see the bundle
 * services.php if you need per-environment values).
 *
 * NB: no `-it` flags — those need a TTY, which a background worker doesn't have.
 */
class ExactCommandRunner {

  public function __construct(
    private readonly string $container = 'exact_app',
    private readonly string $python = 'python',
  ) {}

  /**
   * Runs the command and returns a structured result.
   *
   * @param array $args The `manage.py` subcommand and its arguments.
   * @param integer $timeoutSeconds How long to wait for the command to finish.
   * @return array
   */
  public function run(array $args, int $timeoutSeconds = 600): array {
    $argv = array_merge(['docker', 'exec', $this->container, $this->python, 'manage.py'], $args);

    $process = new Process($argv);
    $process->setTimeout($timeoutSeconds);

    $startedMs = (int) (microtime(true) * 1000);
    $process->run();
    $durationMs = (int) (microtime(true) * 1000) - $startedMs;

    return [
      'command'    => $process->getCommandLine(),
      // getExitCode() is null if the process never started; treat that as -1.
      'exitCode'   => $process->getExitCode() ?? -1,
      'stdout'     => $process->getOutput(),
      'stderr'     => $process->getErrorOutput(),
      'durationMs' => $durationMs,
    ];
  }
}

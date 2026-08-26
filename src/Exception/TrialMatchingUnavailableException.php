<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Exception;

/**
 * TrialMatchingUnavailableException
 * =================================
 *
 * Thrown when EXACT's matching API could not be consulted at all: the host is
 * unreachable, the token was rejected, the response was not JSON, or the server
 * returned a 5xx. It never means "this patient matched nothing" — that is a
 * perfectly successful call that returns an empty list.
 *
 * ── Why this throws when PatientManager and TrialsManager swallow ───────────
 * The two DBAL managers deliberately turn a dead database into a logged warning
 * and an empty array. That is right for them: losing patient *names* should not
 * cost you the ability to read your evaluation *results*, and an empty list of
 * names degrades into "Patient 1234" in the template. The caller genuinely does
 * not need to know.
 *
 * Matching cannot take that posture, because here the two outcomes are not
 * distinguishable by their value:
 *
 *   "EXACT considered 367 trials and none fit this patient"  → []
 *   "EXACT was never reached"                                → []
 *
 * Returning [] for both would render an empty trial table in the second case,
 * and an empty table on a screen headed "candidate trials" reads as a clinical
 * finding. It says *there is nothing for this patient* when the truth is *we
 * did not ask*. That is the one failure mode this feature must not have, so the
 * distinction is carried in the type system rather than in a boolean somebody
 * can forget to check.
 *
 * The controller catches this and falls back to the full unranked DBAL list
 * with a visible warning — the list is then honest about being unranked, which
 * an empty table never could be.
 *
 * ── What NOT to put in the message ──────────────────────────────────────────
 * These messages reach a flash on a clinical screen and reach the log. Neither
 * is a place for the patient payload. Name the endpoint, the status code and
 * the transport failure; never echo back the patientInfo that was sent, and
 * never include the token. The whole point of the inline patient-context design
 * is that PHI is in flight briefly and stored nowhere — writing it into an
 * exception message undoes that in the one file most likely to be logged.
 */
class TrialMatchingUnavailableException extends \RuntimeException {

  /**
   * @param string $reason A short operator-facing explanation, safe to log.
   *   Endpoint, status code, transport error. No PHI, no credentials.
   * @param \Throwable|null $previous The underlying HttpClient exception, kept
   *   for the stack trace in the profiler.
   */
  public function __construct(string $reason, ?\Throwable $previous = null) {
    parent::__construct(
      sprintf('EXACT trial matching is unavailable: %s', $reason),
      previous: $previous,
    );
  }
}

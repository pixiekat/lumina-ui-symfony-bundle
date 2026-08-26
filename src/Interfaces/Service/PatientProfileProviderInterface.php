<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Interfaces\Service;

use Pixiekat\LuminaUiBundle\Exception\TrialMatchingUnavailableException;

/**
 * PatientProfileProviderInterface
 * ===============================
 *
 * Builds the `patientInfo` payload EXACT's matching engine consumes, from a
 * ctomop person id.
 *
 * ── Why this is separate from TrialMatcherInterface ─────────────────────────
 * The matcher's job is "ask EXACT which trials fit this profile". Assembling
 * the profile is a different job with a different dependency (ctomop, not the
 * trials API) and a different reason to change (ctomop's schema, not EXACT's
 * search contract). Splitting them means the matcher can be tested against a
 * stub profile with no database at all, and this can be tested against a
 * captured row with no matching involved.
 *
 * It is also the seam where the profile could come from somewhere else entirely
 * — a form a clinician fills in by hand, say, for a patient not yet in ctomop.
 * That is a plausible near-term feature, and it lands as a second implementation
 * of this interface rather than as a branch inside the matcher.
 *
 * ── This is the PHI chokepoint ──────────────────────────────────────────────
 * Every clinical value that leaves this application for EXACT passes through an
 * implementation of this interface. Keeping that true is the point: as long as
 * it holds, the questions "what do we send upstream?" and "what should we stop
 * sending?" have exactly one file to read. The moment a controller starts
 * assembling its own payload, that guarantee is gone and the answer becomes a
 * search.
 */
interface PatientProfileProviderInterface {

  /**
   * Builds the EXACT-shaped patient profile for one ctomop person.
   *
   * @param int $personId The ctomop `person.person_id`.
   *
   * @return array<string, mixed>|null The profile, ready to send as the
   *   `patientInfo` value — camelCase keys, EXACT's vocabularies, derived fields
   *   left for EXACT to compute.
   *
   *   Null means this person has NO clinical record to match on (no
   *   `patient_info` row). That is a real answer, not an error: the person
   *   exists, they simply have nothing recorded. Callers must not send `{}` in
   *   its place — an empty profile disqualifies nobody, so EXACT would return
   *   the entire corpus as "potential" and the screen would show a confident
   *   ranking built from no information at all.
   *
   * @throws TrialMatchingUnavailableException When the profile could not be
   *   built: ctomop unreachable, or the normalizer refused the row. Distinct
   *   from the null case above — here we do not know what the patient's data is,
   *   rather than knowing there is none.
   */
  public function buildFor(int $personId): ?array;
}

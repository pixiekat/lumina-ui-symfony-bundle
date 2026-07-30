<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Interfaces\Service;

use Pixiekat\LuminaUiBundle\ReadModel\Trial;

/**
 * TrialsManagerInterface
 * ======================
 *
 * The one and only doorway from Lumina UI into the EXACT trials database, and
 * the exact counterpart of PatientManagerInterface. Same three reasons for
 * existing: it makes controllers testable without a live exact_db, it puts the
 * complete list of questions we may ask EXACT on a single page, and it leaves
 * room to swap the SQL implementation for EXACT's HTTP API later without
 * touching a caller.
 *
 * Read-only by construction — no create/update/delete, and the `trials` DBAL
 * connection behind it has no entity manager, so there is no write path to
 * misuse even if somebody added one here.
 */
interface TrialsManagerInterface {

  /**
   * Fetches one trial by its internal `trials_trial.id`.
   *
   * Returns null rather than throwing on an unknown id. This matters more here
   * than on the patient side: exact_db hosts two databases with an identical
   * trials_trial schema but different id ranges, so pointing the connection at
   * the other one turns every lookup into a legitimate miss. The UI should say
   * "trial not found in this database" and carry on.
   */
  public function find(int $trialId): ?Trial;

  /**
   * Fetches many trials in ONE query, keyed by id.
   *
   * The N+1 killer for the evaluations table, exactly as on the patient side:
   * gather the trial ids from a page of Evaluation rows, call this once, then
   * index into the result. Ids with no row are simply absent — always use
   * `$trials[$id] ?? null` rather than assuming presence.
   *
   * @param int[] $trialIds
   * @return array<int, Trial> Keyed by trials_trial.id.
   */
  public function findMany(array $trialIds): array;

  /**
   * Looks a trial up by its registry identifier, e.g. "NCT06150664".
   *
   * No equivalent exists on the patient side, and it earns its place here: the
   * NCT number is how clinicians actually refer to a trial, so a picker or a
   * paste-in box wants this far more often than it wants an internal id.
   * Matching is case-insensitive; registry ids are conventionally uppercase but
   * hand-typed input will not be.
   */
  public function findByStudyId(string $studyId): ?Trial;

  /**
   * Searches trials for a picker UI.
   *
   * @param string|null $query Free text over study_id, brief_title and
   *   official_title. Null/empty means "all trials".
   * @param int $limit  Page size — always capped by the implementation.
   * @param int $offset Rows to skip.
   * @return Trial[] Ordered for stable pagination.
   */
  public function search(?string $query = null, int $limit = 25, int $offset = 0): array;

  /**
   * Total trials matching the same filter search() would apply, so a pager can
   * render "page 2 of 8" without fetching every row.
   */
  public function countAll(?string $query = null): int;
}

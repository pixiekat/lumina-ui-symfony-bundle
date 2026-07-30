<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Interfaces\Service;

use Pixiekat\LuminaUiBundle\ReadModel\Patient;

/**
 * PatientManagerInterface
 * =======================
 *
 * The one and only doorway from Lumina UI into the ctomop patient database.
 *
 * Why an interface at all, when there is exactly one implementation?
 *
 *   1. Tests. A controller typed against this interface can be handed a
 *      hand-rolled in-memory stub, so the test suite never needs a running
 *      ctomop container. (This matters more than it sounds: 107 synthetic
 *      patients are not something you want to seed per test.)
 *   2. It documents the boundary. Everything Lumina is allowed to ask ctomop is
 *      listed right here, on one page. If a method is not on this list, no
 *      controller can make that query — the temptation to scatter ad-hoc SQL
 *      through the app never gets a foothold.
 *   3. Swappability. If ctomop ever grows a proper HTTP API (it already has a
 *      swagger endpoint), an HttpPatientManager can replace the SQL one without
 *      touching a single caller.
 *
 * Note the deliberate absence of any save/create/delete method. This contract is
 * read-only by design, and the `patients` DBAL connection has no entity manager
 * behind it, so there is no writing path even if somebody wanted one.
 */
interface PatientManagerInterface {

  /**
   * Fetches one patient by OMOP person_id.
   *
   * Returns null rather than throwing when the id is unknown — an evaluation row
   * can reference a person that has since been removed from ctomop, and the UI
   * should show "patient no longer in ctomop" rather than a 500.
   */
  public function find(int $personId): ?Patient;

  /**
   * Fetches many patients in ONE query, keyed by person_id.
   *
   * This exists purely to kill the N+1 problem on the evaluations table: the
   * index page has 25 Evaluation rows, each carrying a personId, and calling
   * find() in a loop would mean 25 round trips to a second database. Collect the
   * ids first, call this once, then look each one up from the returned map.
   *
   * Ids with no matching row are simply absent from the result — callers should
   * use `$patients[$id] ?? null`, never assume presence.
   *
   * @param int[] $personIds
   * @return array<int, Patient> Keyed by person_id.
   */
  public function findMany(array $personIds): array;

  /**
   * Searches patients for a picker UI.
   *
   * @param string|null $query Free text: matches name, or an exact person_id when
   *   the string is all digits. Null/empty means "everyone".
   * @param int $limit  Page size. Cap this — never let a caller ask for all rows.
   * @param int $offset Rows to skip.
   * @return Patient[] Ordered for stable pagination.
   */
  public function search(?string $query = null, int $limit = 25, int $offset = 0): array;

  /**
   * Total number of patients matching the same filter search() would apply.
   * Separate from search() so a pager can show "page 2 of 5" without fetching
   * every row.
   */
  public function countAll(?string $query = null): int;
}

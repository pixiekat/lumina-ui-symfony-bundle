<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Twig\Runtime;

use Pixiekat\LuminaUiBundle\Interfaces\Service\PatientManagerInterface;
use Pixiekat\LuminaUiBundle\ReadModel\Patient;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Backs the `find_patient(id)` Twig function.
 *
 * Living in a *Runtime* rather than the extension itself is what keeps this
 * lazy: Twig instantiates the runtime only when a template actually calls the
 * function, so pages that never mention a patient never construct a
 * PatientManager or touch a cache pool. The extension class stays a cheap
 * declaration of "these names exist".
 *
 * Two layers of caching sit in front of ctomop, and they solve different problems:
 *
 *   1. $seen — a plain array, request-local. Kills repeat lookups *within one
 *      render*. An evaluations table with twenty-five rows pointing at the same
 *      patient costs one lookup, not twenty-five.
 *   2. $cache — the shared pool, TTL-bounded. Smooths bursts *across* requests
 *      so a refresh does not re-query ctomop for data that has not moved.
 *
 * Neither is a substitute for PatientManager::findMany() when you already know
 * the whole set of ids up front — a cold render still costs one query per
 * distinct patient. If that starts to matter, prime $seen from the controller
 * with a single findMany() call before rendering.
 */
class PatientTwigExtensionRuntime implements RuntimeExtensionInterface {

  /**
   * Cache lifetime in seconds, deliberately short.
   *
   * ctomop is the source of truth; we are only smoothing bursts, not holding a
   * copy. Patient records change, and a stale ECOG score on screen is a worse
   * failure than a slow one — so this is measured in minutes, not hours.
   */
  private const int TTL = 300;

  /**
   * Request-local memo of everything looked up so far, keyed by person_id.
   *
   * Null is a legitimate value here ("we asked, ctomop has no such patient"),
   * which is why every read uses array_key_exists() rather than isset().
   *
   * No invalidation needed: Twig rebuilds the runtime on each request, so this
   * cannot outlive the render it belongs to.
   *
   * @var array<int, Patient|null>
   */
  private array $seen = [];

  public function __construct(
    private readonly CacheInterface $cache,
    private readonly PatientManagerInterface $patientManager,
  ) {}

  /**
   * Resolves one ctomop patient for a template, or null when the id is unknown.
   *
   * Returning null rather than throwing is deliberate: an Evaluation row stores
   * a bare person_id and can outlive the patient it references. The template
   * should be able to say "patient no longer in ctomop" instead of taking the
   * whole page down with it.
   */
  public function findPatient(int $id): ?Patient {
    // array_key_exists, not isset — a memoised null is a real answer, and isset()
    // would treat it as "never looked up" and re-query on every single row.
    if (array_key_exists($id, $this->seen)) {
      return $this->seen[$id];
    }

    return $this->seen[$id] = $this->cache->get(
      // Namespaced key. PSR-6 reserves {}()/\@: — an int can never contain those,
      // but the prefix keeps us from colliding with anything else sharing cache.app.
      'lumina_ui.patient.' . $id,

      /**
       * Compute-on-miss callback. This is the whole reason to prefer the cache
       * *contracts* API over raw PSR-6: read, compute and write are one atomic
       * call, so there is no way to forget the save() — and Symfony adds
       * stampede protection (early recompute) on top for free.
       *
       * @param bool $save Set false to compute a value and decline to store it.
       */
      function (ItemInterface $item, bool &$save) use ($id): ?Patient {
        $item->expiresAfter(self::TTL);

        $patient = $this->patientManager->find($id);

        // Do not persist a miss. A patient added to ctomop thirty seconds from
        // now should not stay invisible for the rest of the TTL. The request-local
        // $seen memo still holds it, so a single render never asks twice.
        if ($patient === null) {
          $save = false;
        }

        return $patient;
      },
    );
  }
}

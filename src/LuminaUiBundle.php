<?php

declare(strict_types=1);

namespace Pixiekat\LuminaUiBundle;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * LuminaUiBundle
 * ==============
 *
 * The Lumina UI bundle is the *real* home of the evaluation feature set. The
 * surrounding `lumina-ui` application is intentionally thin: it is just a host
 * Symfony app that boots this bundle. Everything domain-specific lives here so
 * it can be versioned and reused on its own (Codeberg: pixiekat/lumina-ui-symfony-bundle).
 *
 * Responsibilities (built out incrementally):
 *   - Doctrine entities describing an "evaluation" of a patient against a trial
 *     using a given matching software (EXACT, MatchMiner, ...).
 *   - Doctrine migrations that create those tables in the `lumina_db` database.
 *   - Controllers / templates that list evaluations and expose "re-run" links,
 *     which shell out to the EXACT container, e.g.:
 *         docker exec -it exact_app python manage.py explain_trial_match \
 *             --person-id <id> --trial-id <id>
 *         docker exec -it exact_app python manage.py search_trials_for_patients
 *
 * We extend AbstractBundle (Symfony 7+/8) rather than the legacy Bundle base
 * class: it gives us a single-file configuration/DI surface we can grow into
 * later (configure(), loadExtension()) without a separate Extension class.
 *
 * For now this class is deliberately empty — registering it in the host app's
 * config/bundles.php is all that is needed to make Symfony aware of the bundle.
 */
final class LuminaUiBundle extends AbstractBundle
{
    // Intentionally empty for the wiring step. DI configuration, service
    // definitions and a config tree will be added as the feature grows.
}

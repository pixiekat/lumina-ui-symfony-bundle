<?php

declare(strict_types=1);

namespace Pixiekat\LuminaUiBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
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
    /**
     * Load the bundle's service definitions.
     *
     * AbstractBundle calls this during container compilation. We simply import
     * config/services.php (sibling of this src/ dir), which autowires the
     * bundle's repositories, controllers and message handlers.
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(\dirname(__DIR__) . '/config/services.php');
    }

    /**
     * Let this bundle override templates that ship in *other* bundles.
     *
     * The familiar `templates/bundles/<BundleName>/` convention is deliberately
     * application-only: Symfony resolves it against %kernel.project_dir%/templates
     * and nowhere else, so a bundle can never use it to override a sibling. To
     * make an override travel with this bundle (and therefore apply to every app
     * that boots it) we have to register the directory as a Twig path ourselves.
     *
     * How the priority works: Twig's FilesystemLoader keeps an ordered list of
     * directories per namespace and returns the first file it finds. Paths coming
     * from `twig.paths` config are added before the ones TwigBundle derives from
     * registered bundles, and prependExtensionConfig() puts our config ahead of
     * the host application's twig.yaml. Net effect: the directory below is searched
     * before symfony-common-helpers' own templates/ dir, so our copy wins.
     *
     * The layout beneath the directory must mirror the target bundle exactly —
     * '@PixiekatSymfonyHelpers/user/login.html.twig' looks for 'user/login.html.twig'
     * inside whatever directories are registered under that namespace.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $overrides = [
            // <directory shipped in this bundle> => <namespace of the bundle being overridden>
            '/templates/bundles/PixiekatSymfonyHelpersBundle' => 'PixiekatSymfonyHelpers',
        ];

        foreach ($overrides as $relativePath => $namespace) {
            $path = \dirname(__DIR__) . $relativePath;

            // FilesystemLoader::addPath() throws if a directory is missing, which
            // would take the whole container down. Skipping absent directories keeps
            // an override optional rather than mandatory.
            if (!is_dir($path)) {
                continue;
            }

            $builder->prependExtensionConfig('twig', [
                'paths' => [$path => $namespace],
            ]);
        }

        $this->prependStimulusControllerPath($builder);
    }

    /**
     * Let the Stimulus controllers this bundle ships be discovered.
     *
     * StimulusBundle scans `stimulus.controller_paths`, which defaults to the
     * application's assets/controllers directory and nothing else. Left alone,
     * that would force every bundle-owned controller to be copied into the host
     * app — precisely the split this bundle exists to avoid, since the JS is as
     * much a part of the evaluation feature as the Twig and the PHP.
     *
     * Appending our own directory keeps the whole feature in one repository. The
     * files themselves live under Resources/public/, which Symfony's AssetMapper
     * maps automatically for every registered bundle (as `bundles/luminaui/…`) —
     * so they are both discoverable as controllers and servable as assets with
     * no configuration in the host app at all.
     *
     * ── Why the app's own directory is listed here too ──────────────────────
     * `controller_paths` DEFAULTS to ['%kernel.project_dir%/assets/controllers'],
     * and a default only applies when nothing supplies a value. The moment we
     * prepend ours, that default stops applying — so listing only the bundle's
     * directory would silently stop the host application's own controllers from
     * being discovered at all. (Here that would have taken out
     * csrf_protection_controller.js, which is not a cosmetic loss.) Restating the
     * conventional path alongside ours preserves it.
     *
     * If the host app configures `stimulus.controller_paths` explicitly, its
     * config merges with this one and the shared entry appears twice; harmless,
     * because the controllers map is keyed by name.
     */
    private function prependStimulusControllerPath(ContainerBuilder $builder): void
    {
        $paths = [
            // The host app's conventional directory — see the note above.
            $builder->getParameter('kernel.project_dir') . '/assets/controllers',
            // This bundle's own controllers.
            \dirname(__DIR__) . '/Resources/public/controllers',
        ];

        // StimulusBundle hands these straight to Finder::in(), which throws on a
        // missing directory. Filtering keeps an absent directory from taking the
        // whole container down at compile time.
        $paths = array_values(array_filter($paths, is_dir(...)));

        if ($paths === []) {
            return;
        }

        $builder->prependExtensionConfig('stimulus', [
            'controller_paths' => $paths,
        ]);
    }
}

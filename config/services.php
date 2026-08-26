<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Bundle service wiring.
 *
 * Autowire + autoconfigure everything under src/, EXCEPT the things that are
 * data, not services:
 *   - Entity/    — Doctrine entities (managed by the ORM, never the container)
 *   - Enum/      — pure value objects
 *   - Message/   — Messenger message DTOs (the *handlers* in MessageHandler/ ARE
 *                  services and stay registered)
 *   - ReadModel/ — immutable snapshots built by hand inside services (Patient),
 *                  not wired by the container
 *   - the Bundle class itself
 *
 * Interfaces/ needs no exclusion: Symfony's PSR-4 loader already skips
 * interfaces, abstract classes and traits. It DOES, however, auto-alias a
 * single implementation to its interface — which is why typing a controller
 * against PatientManagerInterface resolves to PatientManager with no extra
 * configuration.
 *
 * Autoconfigure is what makes the magic tags apply automatically:
 *   - repositories extending ServiceEntityRepository → doctrine.repository_service
 *   - controllers extending AbstractController        → controller.service_arguments
 *   - #[AsMessageHandler] handlers                    → messenger.message_handler
 */
return static function (ContainerConfigurator $container): void {
    $bundleDir = \dirname(__DIR__);

    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->load('Pixiekat\\LuminaUiBundle\\', $bundleDir . '/src/')
        ->exclude([
            $bundleDir . '/src/Entity/',
            $bundleDir . '/src/Enum/',
            $bundleDir . '/src/Exception/',
            $bundleDir . '/src/Message/',
            $bundleDir . '/src/ReadModel/',
            $bundleDir . '/src/LuminaUiBundle.php',
        ]);

    /*
     * The scoped HTTP client for EXACT's trial-search API.
     *
     * ── Why a scoped client rather than injecting `http_client` ─────────────
     * The base URI and — much more importantly — the Authorization header are
     * bound to the service, not passed at each call site. That means the DRF
     * token exists in exactly one place in the container and no service that
     * makes a request is ever holding it. A file that cannot see a credential
     * cannot leak it into a log line, an exception message or a request to the
     * wrong host.
     *
     * It is also what makes ExactTrialMatcher's refusal to follow the API's
     * absolute `next` URLs enforceable: every request it can make is relative
     * to this base URI.
     *
     * `withOptions()` returns a NEW HttpClientInterface wrapping the shared
     * transport, so this inherits connection pooling, the profiler timeline and
     * the retry decorator from the framework's own client rather than opening
     * an unmanaged second one.
     *
     * ── The trailing slash on EXACT_API_URL is load-bearing ────────────────
     * base_uri resolution follows RFC 3986, the same rules as an <a href> in a
     * page. "http://host/exact/" + "trials/match/" is what we want;
     * "http://host/exact" + "trials/match/" resolves to "http://host/trials/
     * match/" — the last path segment is treated as a filename and replaced.
     * That failure surfaces as a puzzling 404, which is why the matcher's 404
     * branch names this variable explicitly.
     *
     * ── Timeouts ───────────────────────────────────────────────────────────
     * `timeout` is inactivity between bytes; `max_duration` is the hard ceiling
     * on the whole request. Both are set because this runs inside a page render
     * a clinician is waiting on: a matching API that has gone slow should cost
     * them a warning and the unranked list after a few seconds, not a browser
     * spinner until PHP's own limit fires. Matching a few hundred trials is
     * ~150ms in practice, so 15s is generous rather than tight.
     */
    $services->set('lumina_ui.exact_api.client', HttpClientInterface::class)
        ->factory([service('http_client'), 'withOptions'])
        ->args([[
            'base_uri' => '%env(EXACT_API_URL)%',
            'headers' => [
                // DRF's TokenAuthentication scheme. The word "Token", not "Bearer".
                'Authorization' => 'Token %env(EXACT_API_TOKEN)%',
                'Accept' => 'application/json',
            ],
            'timeout' => 5,
            'max_duration' => 15,
        ]]);
};

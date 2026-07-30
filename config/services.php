<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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
            $bundleDir . '/src/Message/',
            $bundleDir . '/src/ReadModel/',
            $bundleDir . '/src/LuminaUiBundle.php',
        ]);
};

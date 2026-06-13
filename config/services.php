<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Bundle service wiring.
 *
 * Autowire + autoconfigure everything under src/, EXCEPT the things that are
 * data, not services:
 *   - Entity/  — Doctrine entities (managed by the ORM, never the container)
 *   - Enum/    — pure value objects
 *   - Message/ — Messenger message DTOs (the *handlers* in MessageHandler/ ARE
 *                services and stay registered)
 *   - the Bundle class itself
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
            $bundleDir . '/src/LuminaUiBundle.php',
        ]);
};

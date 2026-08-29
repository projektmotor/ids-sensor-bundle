<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

/**
 * Die Konfigurationsvarianten, über die der Container-Abdruck verglichen wird.
 *
 * WARUM MEHRERE VARIANTEN NÖTIG SIND
 *
 * Die zehn Verdrahtungsdateien werden BEDINGT importiert — je nachdem, welche Ebenen
 * eingeschaltet sind, ob SecurityBundle vorhanden ist, ob eine Transport-DSN gesetzt ist. Ein
 * Abdruck nur der Standardkonfiguration ließe genau die Zweige ungeprüft, in denen ein
 * Refactor am ehesten etwas verliert: die abgeschalteten und die alternativen.
 *
 * Jede Variante deckt einen Zweig ab, der sonst niemand prüft.
 *
 * @internal
 */
final class ContainerVariants
{
    /** @var array<string, mixed> */
    private const BASE = [
        'application_id' => '9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31',
        'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
        'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
    ];

    /**
     * @return iterable<string, array{sensor: array<string, mixed>, security: array<string, mixed>|null, debug: bool}>
     */
    public static function all(): iterable
    {
        // Der Ausgangszustand nach `composer require`: kein Transport, keine Security.
        yield 'minimal' => self::variant();

        // Der Regelfall in Produktion: Transport gesetzt, Security vorhanden.
        yield 'vollausbau' => self::variant(
            ['collector' => ['base_uri' => 'https://collector.test', 'username' => 'sensor', 'password' => 'geheim']],
            SecurityConfig::basic(),
        );

        // Ohne SecurityBundle: die Security-Ebene darf nicht im Container auftauchen.
        yield 'ohne-security-bundle' => self::variant(
            ['collector' => ['base_uri' => 'https://collector.test', 'username' => 'sensor', 'password' => 'geheim']],
        );

        // Security vorhanden, Ebene aber abgeschaltet — ein anderer Zweig als „Bundle fehlt".
        yield 'security-aus' => self::variant(
            ['layers' => ['security' => ['enabled' => false]]],
            SecurityConfig::basic(),
        );

        // Der teuerste Sensor einzeln abgeschaltet: der Decorator muss verschwinden.
        yield 'access-decision-aus' => self::variant(
            ['layers' => ['security' => ['access_decision' => false]]],
            SecurityConfig::basic(),
        );

        // Die drei Business-Erfassungsmodi verändern jeweils andere Services.
        foreach (['dispatcher', 'recorder', 'configured'] as $mode) {
            yield 'business-'.$mode => self::variant([
                'layers' => ['business' => [
                    'capture_mode' => $mode,
                    'event_classes' => 'configured' === $mode ? [OrderAmountOverridden::class] : [],
                ]],
            ]);
        }

        yield 'business-aus' => self::variant(['layers' => ['business' => ['enabled' => false]]]);

        yield 'kernel-aus' => self::variant(['layers' => ['kernel' => ['enabled' => false]]]);

        yield 'heartbeat-aus' => self::variant(['heartbeat' => ['enabled' => false]]);

        yield 'raw-aus' => self::variant(['raw' => ['enabled' => false]]);

        // Erzwungener Spool-First-Betrieb (das mod_php-Laufzeitmodell).
        yield 'spool-first' => self::variant(['flush' => ['policy' => 'spool']]);

        // Ohne Debug fehlen die Debug-Decorators des Frameworks — der Objektgraph, in dem
        // unsere beiden Decorators sitzen, ist dort ein anderer.
        yield 'ohne-debug' => self::variant(
            ['collector' => ['base_uri' => 'https://collector.test', 'username' => 'sensor', 'password' => 'geheim']],
            SecurityConfig::basic(),
            debug: false,
        );
    }

    /**
     * @param array<string, mixed>      $sensor
     * @param array<string, mixed>|null $security
     *
     * @return array{sensor: array<string, mixed>, security: array<string, mixed>|null, debug: bool}
     */
    private static function variant(array $sensor = [], ?array $security = null, bool $debug = true): array
    {
        return [
            'sensor' => array_replace_recursive(self::BASE, $sensor),
            'security' => $security,
            'debug' => $debug,
        ];
    }
}

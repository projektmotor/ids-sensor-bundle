<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup;

use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lädt die versionierte Redaktionsliste zur CONTAINER-COMPILE-ZEIT.
 *
 * Warum nicht zur Laufzeit: eine YAML-Datei pro Request zu lesen und zu parsen wäre
 * Dateizugriff im Request-Pfad — laut Konzept 2.1 Sensorik ausgeschlossen. Die Liste ist
 * über die Lebensdauer eines Deployments konstant und gehört damit in den kompilierten
 * Container.
 *
 * addResource() sorgt dafür, dass eine Änderung an der Datei den Container-Cache
 * invalidiert. Ohne das würde eine erweiterte Denylist erst beim nächsten
 * Cache-Neuaufbau wirksam — man hätte die Lücke geschlossen und wäre trotzdem offen.
 *
 * @internal
 */
final class RulesLoader
{
    /**
     * @param list<string> $additionalHeaders
     * @param list<string> $additionalParameters
     *
     * @return array{version: int, headers: list<string>, parameters: list<string>}
     */
    public function load(
        string $path,
        array $additionalHeaders,
        array $additionalParameters,
        ContainerBuilder $builder,
    ): array {
        // Die Lesbarkeitsprüfung steht VOR addResource(): `new FileResource()` wirft bei
        // einer fehlenden Datei selbst, und zwar mit „The file … does not exist" — die
        // ausführliche Begründung unten war damit für den wahrscheinlichsten Fall
        // überhaupt (Tippfehler im Pfad) unerreichbar. Eine Ressource für eine Datei
        // anzumelden, deren Fehlen die Kompilierung ohnehin abbricht, hat auch keinen
        // Zweck: Es gibt dann keinen Cache, den ihr Anlegen verwerfen könnte.
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(\sprintf('Die Redaktionsliste "%s" ist nicht lesbar. Ohne sie würde ungeprüft ausgeliefert, was Konzept 4.5.1 ausdrücklich redigieren will — deshalb bricht die Kompilierung ab statt stillschweigend eine leere Liste zu benutzen.', $path));
        }

        $builder->addResource(new FileResource($path));

        $parsed = Yaml::parseFile($path);

        if (!\is_array($parsed)) {
            throw new InvalidArgumentException(\sprintf('Die Redaktionsliste "%s" enthält kein Objekt.', $path));
        }

        return [
            'version' => $this->intValue($parsed, 'version', $path),
            'headers' => array_values(array_unique(array_merge(
                $this->stringList($parsed, 'headers', $path),
                $additionalHeaders,
            ))),
            'parameters' => array_values(array_unique(array_merge(
                $this->stringList($parsed, 'parameters', $path),
                $additionalParameters,
            ))),
        ];
    }

    /**
     * @param array<array-key, mixed> $parsed
     */
    private function intValue(array $parsed, string $key, string $path): int
    {
        $value = $parsed[$key] ?? null;

        if (!\is_int($value) || $value < 1) {
            throw new InvalidArgumentException(\sprintf('Die Redaktionsliste "%s" braucht "%s" als positive Ganzzahl. Die Version reist in jedem raw-Feld mit; ohne sie ließe sich später nicht feststellen, nach welcher Fassung ein Event redigiert wurde.', $path, $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parsed
     *
     * @return list<string>
     */
    private function stringList(array $parsed, string $key, string $path): array
    {
        $value = $parsed[$key] ?? null;

        if (!\is_array($value)) {
            throw new InvalidArgumentException(\sprintf('Die Redaktionsliste "%s" braucht "%s" als Liste.', $path, $key));
        }

        $list = [];

        foreach ($value as $entry) {
            if (!\is_string($entry) || '' === trim($entry)) {
                throw new InvalidArgumentException(\sprintf('Die Redaktionsliste "%s" enthält unter "%s" einen leeren oder nicht-textuellen Eintrag.', $path, $key));
            }

            $list[] = trim($entry);
        }

        return $list;
    }
}

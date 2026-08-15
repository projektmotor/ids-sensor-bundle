<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Support\PayloadConfidentialityCleanup;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup\RulesLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Der Lader der Redaktionsliste — fünf Abbruchpfade, keiner davon war geprüft.
 *
 * Die Klasse entscheidet, ob die Kompilierung abbricht oder eine unvollständige
 * Denylist ausgeliefert wird. Ein Fehler hier ist nicht sichtbar: Der Container
 * kompiliert, die Anwendung läuft, und Zugangsdaten stehen im Klartext im Frame.
 */
#[CoversClass(RulesLoader::class)]
final class RulesLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $angelegteDateien = [];

    protected function tearDown(): void
    {
        foreach ($this->angelegteDateien as $datei) {
            @unlink($datei);
        }

        $this->angelegteDateien = [];
    }

    public function testAValidListIsLoadedAndTrimmed(): void
    {
        $pfad = $this->liste("version: 3\nheaders:\n    - '  Cookie  '\nparameters:\n    - password\n");

        $regeln = (new RulesLoader())->load($pfad, [], [], new ContainerBuilder());

        self::assertSame(3, $regeln['version']);
        self::assertSame(['Cookie'], $regeln['headers'], 'Leerraum um einen Eintrag darf keinen zweiten Eintrag ergeben');
        self::assertSame(['password'], $regeln['parameters']);
    }

    /**
     * Die zusätzlichen Einträge kommen aus der Konfiguration und dürfen die Liste nur
     * ERWEITERN — und ein Eintrag, den beide nennen, darf nicht doppelt entstehen.
     */
    public function testAdditionalEntriesAreAppendedWithoutDuplicates(): void
    {
        $pfad = $this->liste("version: 1\nheaders:\n    - Cookie\nparameters:\n    - password\n");

        $regeln = (new RulesLoader())->load($pfad, ['Cookie', 'X-Tenant-Secret'], ['token'], new ContainerBuilder());

        self::assertSame(['Cookie', 'X-Tenant-Secret'], $regeln['headers']);
        self::assertSame(['password', 'token'], $regeln['parameters']);
    }

    /**
     * Eine Änderung an der Liste muss den Container-Cache ungültig machen.
     *
     * Ohne die Ressource würde eine erweiterte Denylist erst beim nächsten
     * Cache-Neuaufbau wirksam — man hätte die Lücke geschlossen und wäre trotzdem offen.
     */
    public function testTheFileIsRegisteredAsAContainerResource(): void
    {
        $pfad = $this->liste("version: 1\nheaders: []\nparameters: []\n");
        $builder = new ContainerBuilder();

        (new RulesLoader())->load($pfad, [], [], $builder);

        $pfade = array_map(static fn (object $ressource): string => (string) $ressource, $builder->getResources());

        self::assertContains($pfad, $pfade);
    }

    public function testAMissingFileAbortsTheCompilation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ist nicht lesbar');

        (new RulesLoader())->load('/gibt/es/nicht.yaml', [], [], new ContainerBuilder());
    }

    /**
     * @param non-empty-string $inhalt
     */
    #[DataProvider('unbrauchbareListen')]
    public function testAnUnusableListAbortsTheCompilation(string $inhalt, string $erwarteterHinweis): void
    {
        $pfad = $this->liste($inhalt);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($erwarteterHinweis);

        (new RulesLoader())->load($pfad, [], [], new ContainerBuilder());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unbrauchbareListen(): iterable
    {
        // Eine YAML-LISTE ist ein Array und kommt bis zur Versionsprüfung — nur ein
        // skalares Dokument scheitert hier.
        yield 'kein Objekt' => ["nur ein Text\n", 'enthält kein Objekt'];
        yield 'Version fehlt' => ["headers: []\nparameters: []\n", 'braucht "version"'];
        yield 'Version ist Text' => ["version: 'eins'\nheaders: []\nparameters: []\n", 'braucht "version"'];
        yield 'Version ist null' => ["version: 0\nheaders: []\nparameters: []\n", 'braucht "version"'];
        yield 'headers fehlt' => ["version: 1\nparameters: []\n", 'braucht "headers"'];
        yield 'parameters ist Text' => ["version: 1\nheaders: []\nparameters: 'password'\n", 'braucht "parameters"'];
        yield 'leerer Eintrag' => ["version: 1\nheaders: ['  ']\nparameters: []\n", 'leeren oder nicht-textuellen Eintrag'];
        yield 'Zahl als Eintrag' => ["version: 1\nheaders: [42]\nparameters: []\n", 'leeren oder nicht-textuellen Eintrag'];
    }

    /**
     * Legt eine Liste im Scratch-Verzeichnis an und meldet sie zum Aufräumen an.
     */
    private function liste(string $inhalt): string
    {
        $pfad = sys_get_temp_dir().'/ids-rules-'.bin2hex(random_bytes(6)).'.yaml';
        file_put_contents($pfad, $inhalt);
        $this->angelegteDateien[] = $pfad;

        return $pfad;
    }
}

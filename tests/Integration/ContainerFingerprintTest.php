<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\ContainerVariants;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;

/**
 * Hält den kompilierten Container fest — über alle Konfigurationsvarianten.
 *
 * WOZU DIESER TEST DA IST
 *
 * Er prüft kein Verhalten, sondern eine Zusage über die Verdrahtung: dass sie sich nicht
 * unbeabsichtigt ändert. Das ist bei diesem Bundle nötig, weil fast jede Abhängigkeit
 * optional ist (`?LoggerInterface $logger = null`, `?CircuitBreaker $breaker = null`). Ein
 * verlorenes Argument führt deshalb NICHT zu einem Fehler, sondern zu einem stillschweigend
 * abgeschalteten Baustein — und genau diese Fehlerklasse fangen Verhaltenstests nur dort, wo
 * jemand daran gedacht hat.
 *
 * WIE MAN DEN ABDRUCK ERNEUERT
 *
 * Ändert sich die Verdrahtung ABSICHTLICH, wird der Referenzabdruck neu erzeugt:
 *
 *     IDS_UPDATE_FINGERPRINTS=1 vendor/bin/phpunit tests/Integration/ContainerFingerprintTest.php
 *
 * Der erzeugte Unterschied gehört dann in die Codeprüfung — er ist die vollständige,
 * maschinell erzeugte Liste dessen, was der Umbau am Container verändert hat. Der Test ist
 * damit kein Hindernis, sondern das Protokoll.
 */
final class ContainerFingerprintTest extends TestCase
{
    private const REFERENCE_DIR = __DIR__.'/../Fixtures/container-fingerprints';

    /**
     * @param array<string, mixed>      $sensorConfig
     * @param array<string, mixed>|null $securityConfig
     */
    #[DataProvider('variants')]
    public function testTheContainerMatchesTheFingerprint(
        string $variant,
        array $sensorConfig,
        ?array $securityConfig,
        bool $debug,
    ): void {
        $actual = $this->fingerprintOf($variant, $sensorConfig, $securityConfig, $debug);
        $reference = self::REFERENCE_DIR.'/'.$variant.'.json';

        if ('' !== (string) getenv('IDS_UPDATE_FINGERPRINTS')) {
            if (!is_dir(self::REFERENCE_DIR)) {
                mkdir(self::REFERENCE_DIR, 0o770, true);
            }

            file_put_contents($reference, $actual);
            self::markTestSkipped(\sprintf('Abdruck "%s" erneuert.', $variant));
        }

        self::assertFileExists(
            $reference,
            \sprintf(
                'Kein Referenzabdruck für "%s". Einmalig erzeugen mit '
                .'IDS_UPDATE_FINGERPRINTS=1 vendor/bin/phpunit %s',
                $variant,
                'tests/Integration/ContainerFingerprintTest.php',
            ),
        );

        self::assertSame(
            (string) file_get_contents($reference),
            $actual,
            \sprintf(
                'Die Verdrahtung der Variante "%s" hat sich geändert. War das beabsichtigt, '
                .'den Abdruck mit IDS_UPDATE_FINGERPRINTS=1 erneuern und den Unterschied prüfen.',
                $variant,
            ),
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>|null, bool}>
     */
    public static function variants(): iterable
    {
        foreach (ContainerVariants::all() as $name => $variant) {
            yield $name => [$name, $variant['sensor'], $variant['security'], $variant['debug']];
        }
    }

    /**
     * Die SAPI darf NICHT in der Verdrahtung stehen.
     *
     * `RuntimeProfile::__construct()` hat `string $sapi = \PHP_SAPI` als Standard, und der
     * wird bei jeder Objekterzeugung zur LAUFZEIT ausgewertet. Würde der Wert dagegen über
     * die Verdrahtung übergeben — etwa als `!php/const PHP_SAPI` —, fröre er beim
     * Kompilieren des Containers ein. In einem gewärmten Container-Image wäre das die SAPI
     * der Build-Umgebung (`cli`) und damit für jede Web-Anfrage falsch: der Sensor hielte
     * die Antwort für abkoppelbar, obwohl mod_php sie nicht abkoppeln kann, und verbrauchte
     * unbemerkt Antwortzeit.
     *
     * Geprüft wird am Abdruck und nicht am Objekt, weil im Testprozess ohnehin `cli` gilt —
     * ein Vergleich der Werte wäre auch bei eingefrorenem Wert grün.
     */
    public function testTheSapiIsNotInTheWiring(): void
    {
        /** @var array{services: array<string, array{arguments: array<array-key, mixed>}>} $fingerprint */
        $fingerprint = json_decode(
            (string) file_get_contents(self::REFERENCE_DIR.'/minimal.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        $arguments = $fingerprint['services']['ids_sensor.runtime_profile']['arguments'];

        self::assertNotContains(
            \PHP_SAPI,
            $arguments,
            'Die SAPI steht als Wert in der Verdrahtung und wird damit beim Kompilieren eingefroren.',
        );
        self::assertArrayNotHasKey(1, $arguments, 'Argument 1 ist $sapi und muss dem Konstruktor-Default überlassen bleiben');
    }

    /**
     * @param array<string, mixed>      $sensorConfig
     * @param array<string, mixed>|null $securityConfig
     */
    private function fingerprintOf(
        string $variant,
        array $sensorConfig,
        ?array $securityConfig,
        bool $debug,
    ): string {
        $target = sys_get_temp_dir().'/ids-fingerprints/'.$variant.'.json';
        @unlink($target);

        // exposeServices: false — der Abdruck soll die Verdrahtung des BUNDLES zeigen, nicht
        // die von einem Test-Compiler-Pass nachträglich veröffentlichte Fassung.
        $kernel = new TestKernel(
            $sensorConfig,
            'fingerprint-'.$variant,
            false,
            $debug,
            $securityConfig,
            $target,
        );
        $kernel->boot();

        self::assertFileExists(
            $target,
            'Der ContainerFingerprintPass hat nicht geschrieben — vermutlich kam der Container '
            .'aus dem Cache, dann laufen keine Compiler-Pässe.',
        );

        return (string) file_get_contents($target);
    }
}

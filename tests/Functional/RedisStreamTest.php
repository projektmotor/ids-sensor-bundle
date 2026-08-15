<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use ProjektMotor\IdsEventData\Event\EventSchema;
use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Delivery\Transport\Message\EventBatch;
use ProjektMotor\IdsSensor\Delivery\Transport\MessageSerializer;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;

/**
 * Der Nachweis gegen echtes Redis — unter der gehärteten ACL, nicht als root.
 *
 * Das ist der entscheidende Punkt dieses Tests. Unter einem unbeschränkten
 * Redis-Nutzer funktioniert auch eine fehlerhafte Konfiguration; der häufigste
 * Erstinstallationsfehler (`auto_setup` nicht abgeschaltet) fällt dann erst beim
 * ersten Versand in Produktion auf. Hier verbindet sich der Sensor als Nutzer mit
 * ausschließlich XADD-Recht — genau der asymmetrischen Rechteverteilung aus
 * Konzept 2.
 *
 * Wird per Gruppe "redis" gesteuert und übersprungen, wenn kein Broker bereitsteht.
 */
#[Group('redis')]
final class RedisStreamTest extends IntegrationTestCase
{
    private const STREAM = 'ids:events:shop-api';

    private const GROUP = 'ids-collector';

    private \Redis $admin;

    private string $spoolDir;

    protected function setUp(): void
    {
        // Eigenes Spool-Verzeichnis wie in allen anderen Tests mit Spool. Ohne das greift
        // die Vorgabe %kernel.project_dir%/var/ids-spool, und TestKernel::getProjectDir()
        // zeigt auf tests/Fixtures/ — der ACL-Test unten schreibt seinen abgewiesenen
        // Frame dann ins Repository und hinterlässt ihn dort.
        $this->spoolDir = sys_get_temp_dir().'/ids-redis-'.bin2hex(random_bytes(6));

        if (!\extension_loaded('redis')) {
            self::markTestSkipped('ext-redis ist nicht geladen');
        }

        $adminDsn = getenv('IDS_REDIS_ADMIN');
        if (!\is_string($adminDsn) || '' === $adminDsn) {
            self::markTestSkipped('IDS_REDIS_ADMIN ist nicht gesetzt');
        }

        $host = parse_url($adminDsn, \PHP_URL_HOST) ?: 'redis';
        $port = parse_url($adminDsn, \PHP_URL_PORT) ?: 6379;

        $this->admin = new \Redis();
        try {
            $this->admin->connect((string) $host, (int) $port, 1.0);
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis nicht erreichbar: '.$e->getMessage());
        }

        // Sauberer Ausgangszustand, dann Stream und Gruppe anlegen — so, wie es der
        // Collector täte. Der Sensor darf das ausdrücklich NICHT selbst.
        $this->admin->del(self::STREAM);
        $this->admin->xAdd(self::STREAM, '*', ['init' => '1']);
        $this->admin->xGroup('CREATE', self::STREAM, self::GROUP, '0');
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->admin->del(self::STREAM);
            $this->admin->close();
        }

        foreach (glob($this->spoolDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->spoolDir);
    }

    /**
     * Der Durchstich: der Sensor schreibt mit XADD-only-Rechten in den Stream, und
     * der Frame ist danach vollständig auslesbar.
     */
    public function testTheSensorWritesToTheStreamWithXaddOnlyRights(): void
    {
        $before = $this->admin->xLen(self::STREAM);

        $kernel = $this->bootWithRedis('redis-ok');
        $services = $this->services($kernel);

        /** @var EventBuffer $collector */
        $collector = $services->get('ids_sensor.event_buffer');
        $collector->append($this->scannerRequest());
        $kernel->terminate(Request::create('/'), new Response());

        /** @var Counters $counters */
        $counters = $services->get('ids_sensor.counters');
        self::assertSame(
            0,
            $counters->get(Counters::SHIP_FAILED),
            'Der Versand darf unter der XADD-only-ACL nicht scheitern — sonst braucht der Sensor mehr Rechte als zugesagt',
        );
        self::assertSame(1, $counters->get(Counters::SENT));
        self::assertSame($before + 1, $this->admin->xLen(self::STREAM), 'Genau ein Eintrag pro Request');
    }

    /**
     * Der Inhalt im Stream muss das JSON aus Konzept Abschnitt 3 sein — lesbar ohne
     * jede PHP-Klasse des Sensors. Nur so ist die Paketgrenze wirklich das Format und
     * nicht ein PHP-Typ.
     */
    public function testTheStreamContentIsReadableJsonWithAllMandatoryFields(): void
    {
        $kernel = $this->bootWithRedis('redis-ok');
        $services = $this->services($kernel);

        /** @var EventBuffer $collector */
        $collector = $services->get('ids_sensor.event_buffer');
        $collector->append($this->scannerRequest());
        $kernel->terminate(Request::create('/'), new Response());

        $entries = $this->admin->xRange(self::STREAM, '-', '+');
        self::assertIsArray($entries);

        // Symfonys Redis-Transport legt einen Stream-Eintrag mit dem Feld "message"
        // ab; darin steckt JSON mit den Schlüsseln "body" und "headers". Der Frame ist
        // also einmal eingepackt — für den Collector relevant zu wissen.
        //
        // Dass hier reines JSON steht und nicht `s:956:"…"`, hängt an der
        // Transportoption serializer = 0. Symfonys Vorgabe wäre SERIALIZER_PHP, und
        // dann müsste der Collector unserialize() aufrufen.
        $envelope = null;
        foreach ($entries as $fields) {
            if (\is_array($fields) && isset($fields['message']) && \is_string($fields['message'])) {
                self::assertStringStartsWith(
                    '{',
                    $fields['message'],
                    'Der Stream muss reines JSON enthalten, nicht PHP-serialisierte Daten — '
                    .'sonst ist die Paketgrenze nicht das Format aus Konzept Abschnitt 3',
                );
                $envelope = json_decode($fields['message'], true, 512, \JSON_THROW_ON_ERROR);
            }
        }

        self::assertIsArray($envelope, 'Kein Eintrag mit einem message-Feld im Stream');
        self::assertIsString($envelope['body'] ?? null);

        // Die Header sind der Unterscheidungsmerkmal für den Collector: Event-Batch
        // oder Heartbeat.
        self::assertSame(
            MessageSerializer::TYPE_EVENT_BATCH,
            $envelope['headers'][MessageSerializer::HEADER_TYPE] ?? null,
        );
        self::assertSame(
            EventSchema::SCHEMA_VERSION,
            (int) ($envelope['headers'][MessageSerializer::HEADER_SCHEMA_VERSION] ?? 0),
        );

        /** @var array<string, mixed> $frame */
        $frame = json_decode((string) $envelope['body'], true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('direct', $frame['dispatch_path']);
        self::assertSame('shop-api', $frame['sensor'][EventSchema::FIELD_APPLICATION_ID]);
        self::assertCount(1, $frame['events']);

        foreach (EventSchema::MANDATORY_FIELDS as $field) {
            self::assertArrayHasKey($field, $frame['events'][0], \sprintf('Pflichtfeld "%s" fehlt', $field));
        }

        self::assertSame(
            '/wp-admin/setup-config.php',
            $frame['events'][0][EventSchema::FIELD_PAYLOAD]['path'],
        );
    }

    /**
     * Die Gegenprobe zur ACL: der Sensor-Nutzer darf ausdrücklich NICHT lesen.
     *
     * Damit kann ein Angreifer in der überwachten Anwendung weder abgesendete Events
     * löschen noch die noch nicht konsumierten Events anderer Requests mitlesen —
     * genau die Zusage aus Konzept 2.
     */
    public function testTheSensorUserMayNeitherReadNorDelete(): void
    {
        $sensor = new \Redis();
        $sensor->connect('redis', 6379, 1.0);
        $sensor->auth(['ids_sensor', 'sensor-geheim']);

        // Schreiben: erlaubt.
        self::assertNotFalse($sensor->xAdd(self::STREAM, '*', ['body' => '{}']));

        // Lesen und Löschen: verweigert.
        //
        // phpredis meldet NOPERM NICHT einheitlich: xRange liefert `false` und legt
        // die Meldung in getLastError(), del wirft eine RedisException. Ein Test, der
        // nur auf eine Exception prüft, wäre bei xRange grün, ohne etwas zu beweisen —
        // deshalb werden beide Meldewege akzeptiert und in jedem Fall auf „noperm"
        // geprüft.
        $denied = [
            'xrange' => static fn (\Redis $r): mixed => $r->xRange(self::STREAM, '-', '+'),
            'del' => static fn (\Redis $r): mixed => $r->del(self::STREAM),
        ];

        foreach ($denied as $command => $call) {
            $sensor->clearLastError();
            $verweigert = false;
            $result = null;

            try {
                $result = $call($sensor);
                $verweigert = false === $result
                    || str_contains(strtolower((string) $sensor->getLastError()), 'noperm');
            } catch (\RedisException $e) {
                $verweigert = str_contains(strtolower($e->getMessage()), 'noperm');
            }

            self::assertTrue(
                $verweigert,
                \sprintf(
                    'Der Sensor-Nutzer konnte "%s" ausführen — die ACL ist zu weit gefasst. Ergebnis: %s',
                    $command,
                    var_export($result, true),
                ),
            );
        }

        // Der Stream muss die Löschung überlebt haben.
        self::assertGreaterThan(0, $this->admin->xLen(self::STREAM));

        $sensor->close();
    }

    /**
     * Warum `auto_setup` gesperrt ist — der empirische Beleg, gegen echtes Redis.
     *
     * Mit `auto_setup: true` sendet Messenger beim ersten Zugriff
     * `XGROUP CREATE ... MKSTREAM`. Unter der XADD-only-ACL wird das abgelehnt. Das
     * Tückische: mit unbeschränktem Redis-Nutzer in der Entwicklung funktioniert es,
     * und der Fehler zeigt sich erst beim ersten Versand in Produktion.
     *
     * Der Transport wird hier von HAND gebaut und nicht über die Bundle-Konfiguration:
     * Die lehnt `auto_setup` seit der Sperre in `ConfigurationTree` beim Kompilieren ab,
     * und das ist die bessere Antwort — sie kommt früher. Der Grund für die Sperre ist
     * aber genau die Ablehnung hier, und die gehört weiterhin belegt: Ohne diesen Test
     * bliebe die Sperre eine Behauptung über Redis, die niemand nachgeprüft hat.
     */
    public function testAutoSetupFailsUnderTheHardenedAcl(): void
    {
        $dsn = $this->redisDsn();

        $transport = (new RedisTransportFactory())->createTransport(
            $dsn,
            ['auto_setup' => true, 'stream' => self::STREAM, 'group' => self::GROUP, 'consumer' => 'ids'],
            new MessageSerializer(),
        );

        $this->expectException(TransportException::class);

        $transport->send(new Envelope(new EventBatch(['v' => 1, 'events' => []])));
    }

    private function scannerRequest(): CapturedEvent
    {
        $event = CapturedEvent::now(Layer::Kernel, KernelPayload::EVENT_REQUEST, [
            KernelPayload::FIELD_METHOD => 'GET',
            KernelPayload::FIELD_PATH => '/wp-admin/setup-config.php',
        ]);
        $event->setCorrelationId('req-7f2a1c');

        return $event;
    }

    private function redisDsn(): string
    {
        $dsn = getenv('IDS_REDIS_DSN');

        if (!\is_string($dsn) || '' === $dsn) {
            self::markTestSkipped('IDS_REDIS_DSN ist nicht gesetzt');
        }

        return $dsn;
    }

    /**
     * @param array<string, mixed> $transportOptions
     */
    private function bootWithRedis(string $variant, array $transportOptions = []): TestKernel
    {
        $dsn = $this->redisDsn();

        $kernel = new TestKernel([
            'application_id' => 'shop-api',
            'environment' => 'prod',
            'session_hash' => ['key' => self::SESSION_KEY],
            'transport' => ['dsn' => $dsn, 'options' => $transportOptions],
            'spool' => ['dir' => $this->spoolDir],
            // Der Heartbeat geht über denselben Stream und würde hier mitgezählt. Ihn
            // abzuschalten beseitigt eine Reihenfolgeabhängigkeit: seine Drosselung liegt
            // prozessweit in APCu unter der application_id, die Zahl der Stream-Einträge
            // hinge also davon ab, ob in derselben PHPUnit-Prozessinstanz vorher ein Test
            // mit derselben Kennung lief. Diese Tests prüfen die ACL und das Streamformat,
            // nicht das Lebenszeichen.
            'heartbeat' => ['enabled' => false],
        ], $variant);
        $kernel->boot();

        return $kernel;
    }
}

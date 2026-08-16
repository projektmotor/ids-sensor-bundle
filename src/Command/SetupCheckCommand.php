<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Command;

use ProjektMotor\IdsSensor\Delivery\Heartbeat\Scheduler;
use ProjektMotor\IdsSensor\Delivery\Transport\RuntimeProfile;
use ProjektMotor\IdsSensor\Delivery\Transport\Spool\FileSpool;
use ProjektMotor\IdsSensor\Support\Identity\EnvironmentResolver;
use ProjektMotor\IdsSensor\Support\Identity\InstanceIdProvider;
use ProjektMotor\IdsSensor\Support\Identity\SensorIdentityProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prüft die Betriebsvoraussetzungen — gedacht für den Deploy.
 *
 * WARUM ES DIESEN COMMAND GEBEN MUSS
 *
 * Das Bundle ist im Request-Pfad konsequent fail-open (Konzept 4.): es verschluckt jeden
 * Fehler, statt die überwachte Anwendung zu beschädigen. Diese Eigenschaft hat eine
 * gefährliche Kehrseite — eine Fehlkonfiguration ist im Betrieb NICHT sichtbar. Ein Sensor
 * mit falschem `environment` sendet fleißig Events, die der Collector wegen
 * `env_type NOT NULL` (Konzept 4.2.1) verwirft; von außen ist das von einem stillgelegten
 * Sensor nicht zu unterscheiden. Genau das „lautlose" Versagen, das Konzept 2. als die
 * gefährlichste Angriffsform beschreibt — nur diesmal selbst verursacht.
 *
 * Dieser Command verschiebt solche Fehler dorthin, wo sie auffallen: in den Deploy, mit
 * Rückgabewert ≠ 0.
 *
 * BEFUND ODER HINWEIS
 *
 * Ein BEFUND (Rückgabewert 1) bedeutet: die Erkennung ist ganz oder teilweise
 * wirkungslos. Ein HINWEIS bedeutet: es funktioniert, aber eine Zusage ist eingeschränkt.
 * Die Trennung ist wichtig, weil ein Command, der bei jeder Kleinigkeit fehlschlägt, im
 * Deploy sehr schnell mit `|| true` versehen wird — und dann auch die echten Befunde
 * verschluckt. Mit `--strict` werden Hinweise zu Befunden.
 *
 * Der Befehlsname `ids:sensor:setup-check` ist dokumentiert und stabil; die Klasse
 * selbst ist es nicht.
 *
 * @internal
 */
#[AsCommand(
    name: 'ids:sensor:setup-check',
    description: 'Prüft die Betriebsvoraussetzungen des Sensors. Für den Deploy gedacht.',
)]
final class SetupCheckCommand extends Command
{
    /** @var list<string> */
    private array $findings = [];

    /** @var list<string> */
    private array $hints = [];

    /**
     * @param array<string, mixed> $config die vollständige aufgelöste Konfiguration
     */
    public function __construct(
        private readonly SensorIdentityProvider $identityProvider,
        private readonly EnvironmentResolver $environmentResolver,
        private readonly RuntimeProfile $runtime,
        private readonly array $config,
        // Konkret und nicht nullbar, aus zwei Gründen. Erstens ist er nie null: der
        // Spool wird in services_resilience.yaml unbedingt registriert und von drei
        // Diensten referenziert — der Zweig „Kein Spool konfiguriert" war unerreichbar.
        // Zweitens braucht dieser Command die LESESEITE (Verzeichnis, liegengebliebene
        // Dateien), und die verbirgt SpoolInterface absichtlich; die Prüfung lief
        // deshalb über method_exists(). Dieselbe Begründung wie bei
        // {@see \ProjektMotor\IdsSensor\Delivery\Transport\Spool\SpoolDrainer}.
        private readonly FileSpool $spool,
        private readonly ?Scheduler $heartbeatScheduler = null,
        // Der AUFGELÖSTE Schlüssel, nicht der aus %ids_sensor.config%. Genau darin liegt
        // der Zweck: Die Compile-Zeit-Prüfungen in IdsSensorBundle überspringen jeden
        // Wert mit einem %env()%-Platzhalter und verweisen dafür auf diesen Command.
        private readonly ?string $sessionHashKey = null,
        private readonly ?string $sessionCookieName = null,
        private readonly ?string $appSecret = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Behandelt Hinweise wie Befunde.')
            ->setHelp(<<<'HELP'
                Im Deploy-Skript nach dem Cache-Aufbau aufrufen:

                    php bin/console ids:sensor:setup-check

                Rückgabewert 0 heißt „einsatzfähig", 1 heißt „die Erkennung ist ganz oder
                teilweise wirkungslos". Bitte NICHT mit `|| true` entschärfen — der Sinn des
                Commands ist, dass eine Fehlkonfiguration im Deploy auffällt und nicht erst
                bei der Nachanalyse eines Vorfalls.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('IDS-Sensor — Betriebsprüfung');

        $this->checkIdentity($io);
        $this->checkEnvironment($io);
        $this->checkSessionHash($io);
        $this->checkRawLimits();
        $this->checkTransport($io);
        $this->checkTrustedProxies($io);
        $this->checkRuntime($io);
        $this->checkSpool($io);
        $this->checkHeartbeat($io);
        $this->checkCircuitBreaker($io);
        $this->checkLayers($io);
        $this->checkExtensions($io);

        $strict = true === $input->getOption('strict');

        foreach ($this->hints as $hint) {
            $io->warning($hint);
        }

        foreach ($this->findings as $finding) {
            $io->error($finding);
        }

        if ([] !== $this->findings) {
            return Command::FAILURE;
        }

        if ($strict && [] !== $this->hints) {
            $io->error('Hinweise vorhanden und --strict gesetzt.');

            return Command::FAILURE;
        }

        $io->success('Der Sensor ist einsatzfähig.');

        return Command::SUCCESS;
    }

    private function checkIdentity(SymfonyStyle $io): void
    {
        $identity = $this->identityProvider->get();
        $problems = $identity->validate();

        $io->definitionList(
            ['application_id' => $identity->applicationId],
            ['instance_id' => $identity->instanceId],
            ['environment' => $identity->environment->value],
        );

        foreach ($problems as $problem) {
            $this->findings[] = 'Kennung: '.$problem;
        }

        // Ein zur Compile-Zeit eingebackener Hostname wäre in allen Replicas derselbe und
        // bräche die Aggregationsregel aus Konzept 2.2.1 — jede Instanz sähe aus wie eine.
        //
        // Der Vergleich liegt in InstanceIdProvider, weil dort die Bereinigung liegt.
        // Hier stand `=== gethostname()` gegen die bereits bereinigte Kennung — auf
        // jedem Host, dessen Name gekürzt werden muss (FQDN über 64 Zeichen), ein
        // Falsch-Positiv, mit `--strict` ein Exit 1 für eine richtige Konfiguration.
        $hostname = (string) gethostname();

        if (InstanceIdProvider::matchesHostname($identity->instanceId, $hostname)) {
            return;
        }

        $this->hints[] = \sprintf(
            'instance_id ("%s") entspricht nicht dem Hostnamen ("%s"). Das ist in Ordnung, wenn sie '
            .'bewusst gesetzt wurde — bei mehreren Replicas MUSS sie aber je Instanz unterschiedlich '
            .'sein, sonst sind alle Instanzen in den Auswertungen eine (Konzept 2.2.1).',
            $identity->instanceId,
            $hostname,
        );
    }

    /**
     * Der teuerste Fehler überhaupt — und der unauffälligste.
     */
    private function checkEnvironment(SymfonyStyle $io): void
    {
        if ($this->environmentResolver->isResolvable()) {
            return;
        }

        $this->findings[] = \sprintf(
            'environment "%s" ist nicht auf prod|staging|dev abbildbar. Der Sensor benutzt den '
            .'Rückfallwert, aber die Zuordnung ist geraten. Bitte ids_sensor.environment_map ergänzen. '
            .'Grund für den harten Abbruch: der Collector verlangt env_type NOT NULL (Konzept 4.2.1) — '
            .'ein falscher Wert führt zu stillem Totalverlust dieser Instanz, und der ist von einem '
            .'toten Sensor nicht zu unterscheiden.',
            $this->environmentResolver->configuredValue(),
        );
    }

    /**
     * Die Prüfung, auf die die Compile-Zeit ausdrücklich verweist.
     *
     * `IdsSensorBundle::assertSessionHashKeyIsUsable()` überspringt jeden Schlüssel, der
     * einen `%env()%`-Platzhalter enthält — beim Kompilieren ist er noch nicht aufgelöst.
     * Beide Kommentare dort verweisen für diesen Fall auf diesen Command. Eingelöst war
     * das nicht: Geprüft wurde hier ausschließlich `enabled`.
     *
     * Das traf genau den empfohlenen Weg. Die Fehlermeldung des Bundles und `doc/08:25`
     * schlagen `key: '%env(IDS_SESSION_HASH_KEY)%'` vor — wer der Empfehlung folgte, hatte
     * WEDER die Längen- NOCH die APP_SECRET-Prüfung. `IDS_SESSION_HASH_KEY=geheim` lief
     * durch, und der HMAC aus Konzept 2.2.4 war entsprechend schwach.
     */
    private function checkSessionHash(SymfonyStyle $io): void
    {
        /** @var array{enabled: bool, key: string|null, min_key_length: int} $sessionHash */
        $sessionHash = $this->config['session_hash'];

        if (false === $sessionHash['enabled']) {
            $this->hints[] =
                'session_hash ist abgeschaltet: actor.session_id_hash bleibt null. Die sitzungsbezogenen '
                .'Regeln B8/B9 können damit nicht feuern, und eine Sitzungsverkettung über mehrere '
                .'Requests ist nicht möglich.';

            return;
        }

        $io->definitionList(
            ['Session-Cookie' => $this->sessionCookieName ?? 'aus php.ini (session.name)'],
        );

        $key = $this->sessionHashKey;

        if (null === $key || '' === $key) {
            // Beim Kompilieren wäre das ein Abbruch gewesen; hier kann es trotzdem
            // auftreten, wenn die Umgebungsvariable zur Laufzeit leer ist.
            $this->findings[] =
                'session_hash.key ist zur Laufzeit leer. Die Umgebungsvariable ist offenbar nicht '
                .'gesetzt — actor.session_id_hash bleibt dann in jedem Event null, und die Regeln '
                .'B8/B9 sind wirkungslos.';

            return;
        }

        if (null !== $this->appSecret && '' !== $this->appSecret && $this->appSecret === $key) {
            $this->findings[] =
                'session_hash.key ist identisch mit APP_SECRET (Konzept 2.2.4). Die überwachte '
                .'Anwendung kennt APP_SECRET, ein Angreifer mit Codeausführung also auch — er '
                .'könnte aus einer gestohlenen Event-Datenbank die Session-Hashes nachrechnen und '
                .'genau den Session-Hijacking-Vektor öffnen, den das Hashen verhindern soll.';

            return;
        }

        $minimum = $sessionHash['min_key_length'];

        if (mb_strlen($key) < $minimum) {
            $this->findings[] = \sprintf(
                'session_hash.key ist zur Laufzeit %d Zeichen lang, mindestens %d sind verlangt '
                .'(session_hash.min_key_length). Ist der Schlüssel zu kurz, lässt sich aus einer '
                .'gestohlenen Event-Datenbank die Session-ID zurückrechnen — der '
                .'Session-Hijacking-Vektor aus Konzept 2.2.4.',
                mb_strlen($key),
                $minimum,
            );
        }
    }

    /**
     * Zwei Grenzen, die sich gegenseitig aufheben können.
     *
     * `max_request_body_bytes` begrenzt den JSON-Körper VOR dem Lesen,
     * `max_bytes` das fertige `raw` danach. Sind sie gleich groß, füllt ein Körper an der
     * Grenze das ganze Budget allein — `RawPayload\Builder::capped()` wirft ihn dann als
     * erstes wieder weg. Gelesen, redigiert, verworfen: Der Betreiber sieht ein gekürztes
     * `raw` und keinen Grund dafür.
     *
     * Ein Hinweis und kein Befund, weil nichts kaputt ist: Der Körper fehlt, die Erkennung
     * läuft weiter (sie arbeitet auf `payload`, nicht auf `raw`). Nur die forensische
     * Tiefe, für die die Option da ist, bleibt aus.
     *
     * GEMELDET WIRD ERST BEI ECHT GRÖSSER, NICHT BEI GLEICH
     *
     * Beide Vorgaben stehen auf 32768, und ein Deploy-Check, der sich über die
     * mitgelieferte Konfiguration beschwert, ist keiner — „ein Fehlerkanal, der
     * ununterbrochen meldet, meldet nichts mehr" (dieselbe Begründung wie beim
     * HeartbeatCommand). Bei gleichen Grenzen überleben Körper bis etwa 28 KiB; nur die
     * darüber werden gelesen und wieder verworfen. Das ist die dokumentierte Folge der
     * Vorgabe, keine Fehlkonfiguration.
     *
     * Ein Körper-Limit ÜBER dem raw-Budget kann dagegen niemand gewollt haben: Dort ist
     * jeder Körper, der die eine Grenze ausschöpft, von der anderen garantiert zum
     * Verwerfen verurteilt.
     */
    private function checkRawLimits(): void
    {
        /** @var array{enabled: bool, max_bytes: int, include_request_body: bool, max_request_body_bytes: int} $raw */
        $raw = $this->config['raw'];

        if (!$raw['enabled'] || !$raw['include_request_body'] || 0 === $raw['max_request_body_bytes']) {
            return;
        }

        if (0 === $raw['max_bytes'] || $raw['max_request_body_bytes'] <= $raw['max_bytes']) {
            return;
        }

        $this->hints[] = \sprintf(
            'raw.max_request_body_bytes (%d) ist größer als raw.max_bytes (%d). Ein JSON-Körper, '
            .'der die erste Grenze ausschöpft, überschreitet damit zwangsläufig die zweite und '
            .'wird von der Kappung wieder verworfen — gelesen, redigiert, nie angekommen. '
            .'Entweder max_request_body_bytes senken oder max_bytes anheben.',
            $raw['max_request_body_bytes'],
            $raw['max_bytes'],
        );
    }

    private function checkTransport(SymfonyStyle $io): void
    {
        /** @var array{dsn: string|null, options: array<string, mixed>} $transport */
        $transport = $this->config['transport'];
        $dsn = $transport['dsn'];

        if (null === $dsn || '' === $dsn) {
            $this->findings[] =
                'Keine transport.dsn konfiguriert. Der Sensor erfasst, versendet aber nichts — '
                .'die Events enden im Nichts.';

            return;
        }

        $io->definitionList(['transport' => preg_replace('#://[^@/]*@#', '://***@', $dsn) ?? $dsn]);

        // KEINE auto_setup-Prüfung mehr. Sie stand hier, weil `transport.options` den
        // Wert überschreiben konnte — das lehnt der Konfigurationsbaum jetzt ab, und zwar
        // beim Kompilieren. Ein Befund im Deploy-Check wäre die schwächere Antwort auf
        // dieselbe Frage: Er käme später und ließe sich mit `|| true` abschalten.
    }

    /**
     * Ohne Trusted Proxies ist actor.ip überall die Proxy-IP — und damit ist JEDE
     * IP-basierte Regel aus Konzept 4.3 still wirkungslos.
     */
    private function checkTrustedProxies(SymfonyStyle $io): void
    {
        $trusted = Request::getTrustedProxies();

        if ([] !== $trusted) {
            return;
        }

        $this->hints[] =
            'framework.trusted_proxies ist nicht gesetzt. Steht die Anwendung hinter einem Reverse '
            .'Proxy oder Load Balancer, ist actor.ip dann bei JEDEM Event die Proxy-IP, und alle '
            .'IP-basierten Regeln aus Konzept 4.3 sind wirkungslos — ohne jede Fehlermeldung. Läuft die '
            .'Anwendung direkt am Client, ist dieser Hinweis gegenstandslos.';
    }

    private function checkRuntime(SymfonyStyle $io): void
    {
        $runtime = $this->runtime->describe();

        $io->definitionList(
            ['SAPI' => $runtime['sapi']],
            ['flush.policy' => $runtime['policy']],
            ['Versandweg' => $runtime['dispatch_path']],
        );

        // Die CLI ist immer abkoppelbar — die Prüfung des Web-Laufzeitmodells kann dieser
        // Command also gar nicht leisten. Das ehrlich zu sagen ist besser, als eine
        // Aussage zu treffen, die für den Webserver nicht gilt.
        $io->note(
            'Dieser Command läuft unter der CLI-SAPI. Welches Laufzeitmodell der WEBSERVER benutzt, '
            .'ist hier nicht feststellbar. Bei flush.policy: auto entscheidet der Sensor das im '
            .'Request selbst; der Heartbeat meldet den tatsächlichen Weg (runtime.dispatch_path).',
        );
    }

    private function checkSpool(SymfonyStyle $io): void
    {
        /** @var array{dir: string|null, drain_interval_s: int, max_bytes: int, max_file_bytes: int} $spoolConfig */
        $spoolConfig = $this->config['spool'];

        $this->checkSpoolLimits($spoolConfig['max_bytes'], $spoolConfig['max_file_bytes']);

        // Den AUFGELÖSTEN Pfad, nicht den konfigurierten. `spool.dir` ist per Vorgabe
        // null — erst IdsSensorBundle setzt daraus %kernel.project_dir%/var/ids-spool.
        // Hier stand `$this->config['spool']['dir']`, und weil diese Datei
        // declare(strict_types=1) trägt, warf `is_dir(null)` einen TypeError: der
        // Command, der laut doc/07-betrieb.md im Deploy Pflicht ist und ausdrücklich
        // nicht mit `|| true` entschärft werden soll, brach auf der dokumentierten
        // Mindestkonfiguration ab. Das falsche `@var` in Zeile darüber hatte PHPStan
        // davon abgehalten, es zu sehen.
        $directory = $this->spool->directory();

        if (!is_dir($directory)) {
            // Nicht vorhanden ist in Ordnung — FileSpool legt ihn beim ersten Schreiben
            // mitsamt fehlender Zwischenebenen an. Geprüft wird deshalb der nächste
            // VORHANDENE Vorfahre und nicht das unmittelbare Elternverzeichnis: bei der
            // Vorgabe %kernel.project_dir%/var/ids-spool fehlt in einer frischen
            // Installation regelmäßig auch var/ selbst, und die Prüfung meldete dann
            // einen Befund für einen völlig gesunden Zustand — mit --strict einen
            // Rückgabewert 1 im Deploy.
            $ancestor = self::nearestExistingAncestor($directory);

            if (!is_writable($ancestor)) {
                $this->findings[] = \sprintf(
                    'Das Spool-Verzeichnis "%s" existiert nicht und "%s" ist nicht beschreibbar. '
                    .'Damit gibt es keinen Rückfall bei einem Broker-Ausfall.',
                    $directory,
                    $ancestor,
                );
            }
        } elseif (!is_writable($directory)) {
            $this->findings[] = \sprintf('Das Spool-Verzeichnis "%s" ist nicht beschreibbar.', $directory);
        }

        $this->checkSpoolAge($io, $directory, $spoolConfig['drain_interval_s']);
    }

    /**
     * Zwei Nullen, die den Spool still unbrauchbar machen.
     *
     * Der Konfigurationsbaum lässt bei jeder Zahl die 0 zu — der Typ-Platzhalter für `int`
     * ist 0, und `->min(1)` würde ihn zurückweisen — und weist die fachliche Untergrenze
     * ausdrücklich „dem verbrauchenden Dienst" zu. Für den Circuit Breaker ist das
     * eingelöst; für den Spool tat es niemand, obwohl die Folge dort größer ist.
     *
     * `max_bytes: 0` heißt: `FileSpool::hasRoomFor()` ist immer falsch, JEDER Frame wird
     * verworfen und als `dropped_spool_full` gezählt. Unter mod_php, wo der Spool der
     * einzige Transportweg ist (Konzept 3.3.1), ist das der vollständige Erfassungsausfall
     * — sichtbar nur als wachsender Zähler.
     */
    private function checkSpoolLimits(int $maxBytes, int $maxFileBytes): void
    {
        if (0 === $maxBytes) {
            $this->findings[] =
                'spool.max_bytes ist 0. Der Spool nimmt dann NICHTS auf: jeder Frame wird verworfen '
                .'und als dropped_spool_full gezählt. Unter einer Laufzeit ohne abkoppelbare Antwort '
                .'(mod_php) ist der Spool der einzige Transportweg — dort ist das der vollständige '
                .'Erfassungsausfall.';
        }

        if (0 === $maxFileBytes) {
            $this->hints[] =
                'spool.max_file_bytes ist 0: der Schreiber versiegelt seine Datei nach JEDEM Frame. '
                .'Das funktioniert, erzeugt aber eine Datei je Frame und lässt '
                .'spool.drain_max_files_per_run zum Engpass werden.';
        }
    }

    /**
     * Liegt eine alte Datei im Spool, holt sie niemand ab.
     *
     * Unter Spool-First (mod_php) ist das der Weg in den Totalverlust: der Sensor schreibt,
     * niemand drainiert, der Spool läuft voll und verwirft. Deshalb ist ein zu altes
     * Spool-Element hier ein BEFUND und kein Hinweis.
     */
    /**
     * Der nächste Vorfahre, den es tatsächlich gibt.
     *
     * Bricht spätestens an der Wurzel ab: `dirname('/')` ist wieder `/`, und für relative
     * Pfade endet die Kette bei `.`.
     */
    private static function nearestExistingAncestor(string $directory): string
    {
        $candidate = $directory;

        while (!is_dir($candidate)) {
            $parent = \dirname($candidate);

            if ($parent === $candidate) {
                return $candidate;
            }

            $candidate = $parent;
        }

        return $candidate;
    }

    private function checkSpoolAge(SymfonyStyle $io, string $directory, int $drainInterval): void
    {
        $files = $this->spool->waitingFiles();

        if ([] === $files) {
            $io->definitionList(['Spool' => 'leer']);

            return;
        }

        // Das Alter rechnet der Spool aus, nicht dieser Command: Dieselbe Schleife stand
        // ein zweites Mal in der Heartbeat-PayloadFactory, und zwei Fassungen derselben
        // Zahl sind zwei Gelegenheiten, sie unterschiedlich zu machen (CLAUDE.md §1.9).
        $age = $this->spool->oldestWaitingAgeSeconds() ?? 0;
        $io->definitionList(['Spool' => \sprintf('%d Datei(en), älteste %d s', \count($files), $age)]);

        // Das Dreifache des Intervalls: ein einzelner verpasster Lauf ist noch kein Befund,
        // drei hintereinander sind einer.
        $limit = max(1, $drainInterval) * 3;

        if ($age > $limit) {
            $this->findings[] = \sprintf(
                'Im Spool liegt ein %d s altes Element, erwartet werden höchstens %d s (drei '
                .'Drain-Intervalle). Offenbar läuft "ids:sensor:spool:flush" nicht. Der Spool läuft '
                .'dann voll und verwirft — bei Containern ist die häufigste Ursache ein Drain-Prozess '
                .'in einem ANDEREN Pod, der das Spool-Verzeichnis des Web-Pods nicht sieht.',
                $age,
                $limit,
            );
        }
    }

    /**
     * Zwei Nullen, die den Breaker still wirkungslos machen.
     *
     * Der Konfigurationsbaum lässt bei jeder Zahl die 0 zu — der Typ-Platzhalter für
     * `int` ist 0, und `->min(1)` würde ihn zurückweisen. Die fachliche Untergrenze
     * prüft laut seinem eigenen Docblock „der verbrauchende Service"; für den Breaker
     * tat das niemand. `open_for_s: 0` ist dabei die stillste denkbare
     * Fehlkonfiguration: Fehlschläge werden gezählt, der Zustand meldet `half_open`,
     * und gesperrt wird nie — der Betreiber glaubt, einen Schutz zu haben.
     */
    /**
     * `auto` ist kein Modus, sondern eine Auflösungsregel — siehe
     * {@see \ProjektMotor\IdsSensor\IdsSensorBundle} für die Begründung von `both`.
     */
    private static function wirksamerModus(string $konfiguriert): string
    {
        return 'auto' === $konfiguriert ? 'both (aus auto)' : $konfiguriert;
    }

    private function checkCircuitBreaker(SymfonyStyle $io): void
    {
        /** @var array{enabled: bool, failure_threshold: int, open_for_s: int} $breaker */
        $breaker = $this->config['circuit_breaker'];

        if (!$breaker['enabled']) {
            $io->definitionList(['Circuit Breaker' => 'abgeschaltet']);

            return;
        }

        $io->definitionList([
            'Circuit Breaker' => \sprintf(
                'failure_threshold=%d, open_for_s=%d',
                $breaker['failure_threshold'],
                $breaker['open_for_s'],
            ),
        ]);

        if (0 === $breaker['open_for_s']) {
            $this->findings[] =
                'circuit_breaker.open_for_s ist 0. Der Breaker zählt dann Fehlschläge, sperrt aber '
                .'nie — jeder Request zahlt bei einem Broker-Ausfall weiterhin die vollen Timeouts. '
                .'Genau der Fall, für den es den Breaker gibt, ist damit ungeschützt.';
        }

        if (0 === $breaker['failure_threshold']) {
            $this->hints[] =
                'circuit_breaker.failure_threshold ist 0: der Breaker öffnet beim ERSTEN Fehlschlag. '
                .'Das ist zulässig und gelegentlich gewollt, aber selten Absicht.';
        }
    }

    private function checkHeartbeat(SymfonyStyle $io): void
    {
        if (null === $this->heartbeatScheduler) {
            $this->findings[] =
                'Der Heartbeat ist abgeschaltet. Der Collector erzeugt dann dauerhaft den Alarm '
                .'ids.sensor_silent (Konzept 2.), weil ein schweigender Sensor nicht von einem '
                .'stillgelegten zu unterscheiden ist.';

            return;
        }

        /** @var array{mode: string, interval_s: int} $heartbeat */
        $heartbeat = $this->config['heartbeat'];
        $age = $this->heartbeatScheduler->secondsSinceLastSend();

        $io->definitionList([
            'Heartbeat' => \sprintf(
                'mode=%s, interval=%d s, letzter Versand %s',
                // Der WIRKSAME Modus, nicht der konfigurierte: `auto` wird zur
                // Compile-Zeit auf `both` aufgelöst. Hier stand der konfigurierte Wert —
                // der Deploy-Check zeigte also `auto`, während im Heartbeat `both` steht
                // und ein Betreiber, der beides vergleicht, einen Widerspruch sieht, den
                // es nicht gibt.
                self::wirksamerModus($heartbeat['mode']),
                $heartbeat['interval_s'],
                null === $age ? 'nie' : $age.' s',
            ),
        ]);

        // `request` unter einer Laufzeit ohne abkoppelbare Antwort heißt: gar kein
        // Lebenszeichen. Der Emitter lässt den Request-Pfad dort nicht ans Netz (ein
        // TLS-Handschlag wäre echte Antwortzeit), und der Command ist in diesem Modus
        // nicht zuständig — der Collector meldet dauerhaft ids.sensor_silent, obwohl der
        // Sensor arbeitet. Der Command selbst kann das nicht melden: Er läuft per cron
        // und würde minütlich einen Fehlerbericht erzeugen.
        if ('request' === $heartbeat['mode'] && !$this->runtime->shipsDirectly()) {
            $this->findings[] = \sprintf(
                'heartbeat.mode ist "request", aber diese Laufzeit (%s) kann die Antwort nicht '
                .'abkoppeln. Der Request-Pfad sendet dort bewusst nichts, und der cron-Command ist '
                .'in diesem Modus nicht zuständig — es entsteht ÜBERHAUPT kein Lebenszeichen. '
                .'Auf "command" oder "both" stellen.',
                $this->runtime->sapi(),
            );
        }

        if (null === $age) {
            $this->hints[] =
                'Es wurde noch nie ein Heartbeat gesendet. Bei einer frischen Installation ist das '
                .'erwartbar. Zum Prüfen: "ids:sensor:heartbeat --force".';

            return;
        }

        $limit = max(1, $heartbeat['interval_s']) * 3;

        if ($age > $limit) {
            $this->findings[] = \sprintf(
                'Der letzte Heartbeat liegt %d s zurück, erwartet werden höchstens %d s. Bei '
                .'mode=command fehlt vermutlich der cron- oder systemd-Eintrag; unter einer Laufzeit '
                .'ohne abkoppelbare Antwort (mod_php) ist der Command der EINZIGE Weg.',
                $age,
                $limit,
            );
        }
    }

    /**
     * Konzept 2. verlangt ausdrücklich, die Asymmetrie der drei Ebenen nicht zu
     * verschleiern. Dieser Command ist der Ort, an dem sie sichtbar wird.
     */
    private function checkLayers(SymfonyStyle $io): void
    {
        /** @var array{kernel: array{enabled: bool}, security: array{enabled: bool, access_decision: bool}, business: array{enabled: bool, capture_mode: string}} $layers */
        $layers = $this->config['layers'];

        // Security und Business hängen an der Kernel-Ebene — ActorFactory und
        // RequestSnapshotRegistry sind dort verdrahtet. Ohne sie meldete diese Ausgabe
        // „aktiv" für Ebenen, deren Dienste gar nicht geladen wurden.
        $kernelAktiv = $layers['kernel']['enabled'];
        $abhaengig = static fn (bool $eigen): string => match (true) {
            !$kernelAktiv => 'ABGESCHALTET (Kernel-Ebene aus)',
            $eigen => 'aktiv',
            default => 'ABGESCHALTET',
        };

        $io->definitionList(
            ['Kernel-Ebene' => $kernelAktiv ? 'aktiv' : 'ABGESCHALTET'],
            ['Security-Ebene' => $abhaengig($layers['security']['enabled'])],
            ['Business-Ebene' => \sprintf(
                '%s, capture_mode=%s',
                $abhaengig($layers['business']['enabled']),
                $layers['business']['capture_mode'],
            )],
        );

        if (false === $layers['kernel']['enabled']) {
            $this->findings[] =
                'Die Kernel-Ebene ist abgeschaltet. Damit entfällt die Grundlage nahezu aller Regeln '
                .'aus Konzept 4.3. Zum Senken der Latenz ist das der falsche Hebel: erst '
                .'layers.security.access_decision abschalten, dann sampling.info_rate senken '
                .'(Konzept 2.1).';
        }

        if (false === $layers['security']['enabled']) {
            $this->findings[] =
                'Die Security-Ebene ist abgeschaltet. Anmeldefehler und Autorisierungsablehnungen '
                .'werden nicht erfasst — also die Signale, an denen die Regeln zu Brute-Force und '
                .'Rechteausweitung hängen.';
        }

        if (false === $layers['business']['enabled']) {
            $this->findings[] =
                'Die Business-Ebene ist abgeschaltet. Sie ist die einzige Signalklasse für ERFOLGREICHE '
                .'Angriffe, die die Anwendung bestimmungsgemäß benutzen (Konzept 2.1.3, Szenarien S6, '
                .'S7 ohne Voter, S9) — abgeschaltet erkennt das Bundle nur noch gescheiterte Versuche.';
        }

        // Der Hinweis darunter ist bewusst UNABHÄNGIG vom Schalter und deshalb kein Befund:
        // er beschreibt die fehlende Instrumentierung, also die im Konzept 2. beschriebene
        // Asymmetrie — kein Fehler, aber auch nichts, was ungesagt bleiben darf. Der Befund
        // oben beschreibt etwas anderes: dass jemand die Ebene aktiv abgeschaltet hat.
        $this->hints[] =
            'Die Business-Ebene liefert nur Signale, wenn die Anwendung Events auslöst, die '
            .'SecurityRelevantBusinessEvent implementieren. Ohne sie erzeugen ERFOLGREICHE Angriffe, '
            .'die die Anwendung bestimmungsgemäß benutzen, kein Signal (Konzept 2.1.3, Szenarien S6, '
            .'S7 ohne Voter, S9) — und keine Verschärfung der Kernel-Regeln kompensiert das.';
    }

    private function checkExtensions(SymfonyStyle $io): void
    {
        if (!\function_exists('apcu_enabled') || !@apcu_enabled()) {
            $this->hints[] =
                'APCu ist nicht verfügbar. Zähler und Circuit-Breaker-Zustand laufen dann über '
                .'Dateien — funktionsfähig, aber langsamer, und der Breaker reagiert prozessübergreifend '
                .'träger.';
        }

        /** @var array{dsn: string|null} $transport */
        $transport = $this->config['transport'];
        $dsn = (string) ($transport['dsn'] ?? '');

        if (!str_starts_with($dsn, 'redis')) {
            return;
        }

        if (!\extension_loaded('redis') && !\extension_loaded('relay')) {
            $this->findings[] = 'Die DSN nutzt Redis, aber weder ext-redis noch ext-relay ist geladen.';
        }

        // Der Stolperstein aus der Installation: symfony/redis-messenger ist eine
        // Entwicklungsabhängigkeit DIESES Bundles. Die Anwendung muss es selbst in `require`
        // aufnehmen, sonst registriert Symfony die Transport-Factory nicht — der Befund
        // lautet dann „No transport supports Messenger DSN".
        if (!class_exists('Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory')) {
            $this->findings[] =
                'Die Redis-Transport-Factory fehlt. Die Anwendung muss "symfony/redis-messenger" '
                .'selbst in require aufnehmen — als Entwicklungsabhängigkeit dieses Bundles wird die '
                .'Factory dort nicht registriert.';
        }
    }
}

<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Schreibt einen Abdruck aller Bundle-Services in eine Datei.
 *
 * WOZU
 *
 * Ein Refactor der Verdrahtung ist genau dann geglückt, wenn der kompilierte Container
 * derselbe ist. Grüne Tests belegen das nur mittelbar: sie prüfen Verhalten an den Stellen,
 * die jemand für prüfenswert hielt. Ein vertipptes Argument in einem Service, den kein Test
 * anfasst, bliebe unbemerkt — und bei einem Bundle, dessen Services ausdrücklich optional
 * sind (`?LoggerInterface $logger = null`), heißt „unbemerkt" oft „still abgeschaltet".
 *
 * Der Abdruck macht daraus einen maschinellen Vergleich.
 *
 * WARUM TYPE_AFTER_REMOVING
 *
 * Der Zeitpunkt entscheidet über die Aussagekraft. Ein `Container` gibt nach dem Kompilieren
 * keine Definitionen mehr her, der Abdruck muss also aus einem Compiler-Pass kommen. Er darf
 * aber nicht zu früh laufen:
 *
 *  - `ResolveInstanceofConditionalsPass` läuft in der Phase BEFORE_OPTIMIZATION,
 *  - `AutowirePass` in OPTIMIZATION,
 *  - die entfernenden Pässe danach.
 *
 * Erst nach ihnen stehen die autowired Argumente und die autokonfigurierten Tags aufgelöst in
 * den Definitionen. Ein früherer Abdruck zeigte vor und nach dem Umbau zwangsläufig
 * Unterschiede und wäre wertlos: er verglich eine ausgeschriebene Definition mit einer leeren.
 *
 * @internal
 */
final class ContainerFingerprintPass implements CompilerPassInterface
{
    /**
     * Präfixe, unter denen die Services dieses Bundles laufen.
     *
     * Beide, weil der Abdruck den Wechsel von String-IDs auf Klassennamen überspannen muss —
     * er ist das Werkzeug, mit dem dieser Wechsel geprüft wird.
     */
    private const PREFIXES = ['ids_sensor.', 'ProjektMotor\\IdsSensor\\'];

    /**
     * Ausgeschlossen: die Test-Fixtures. Sie liegen unter demselben Namensraum wie das
     * Bundle und würden den Abdruck mit Dingen füllen, die nicht ausgeliefert werden.
     */
    private const EXCLUDED = 'ProjektMotor\\IdsSensor\\Tests\\';

    /**
     * Fremde IDs, deren Auflösung mitprotokolliert wird.
     *
     * Jede davon ist eine Stelle, an der eine Typauflösung etwas anderes liefert als die
     * ausdrückliche Verdrahtung — der getrackte statt des untracked Token-Speichers, der
     * Standard-Bus der Anwendung statt des eigenen. Im Abdruck sind beide Fälle damit
     * sichtbar, ohne dass man sie einzeln testen muss.
     */
    private const WATCHED = [
        'messenger.default_bus',
        'Symfony\\Component\\Messenger\\MessageBusInterface',
        'security.token_storage',
        'security.untracked_token_storage',
        'Symfony\\Component\\Security\\Core\\Authentication\\Token\\Storage\\TokenStorageInterface',
        'event_dispatcher',
        'security.access.decision_manager',
    ];

    public function __construct(
        private readonly string $file,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $fingerprint = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if (!self::belongsToBundle($id)) {
                continue;
            }

            $fingerprint['services'][$id] = self::describe($definition);
        }

        foreach ($container->getAliases() as $id => $alias) {
            if (!self::belongsToBundle($id)) {
                continue;
            }

            $fingerprint['aliases'][$id] = [
                'target' => (string) $alias,
                'public' => $alias->isPublic(),
            ];
        }

        // Fremdsichten: worauf lösen die Typen auf, bei denen eine Typauflösung etwas
        // anderes liefern würde als die ausdrückliche Verdrahtung? Das ist die kompakteste
        // Form, in der diese Fallen maschinell sichtbar bleiben.
        foreach (self::WATCHED as $id) {
            $fingerprint['watched'][$id] = match (true) {
                $container->hasAlias($id) => 'alias -> '.(string) $container->getAlias($id),
                $container->hasDefinition($id) => 'definition '.(string) $container->getDefinition($id)->getClass(),
                default => 'fehlt',
            };
        }

        // Die Menge der zurücksetzbaren Services.
        //
        // Eine eigene Sektion, weil `kernel.reset` an der Definition NICHT verlässlich
        // ablesbar ist: bei einem Decorator ist der Tag zu diesem Zeitpunkt schon
        // verarbeitet und aus der Definition verschwunden. Ohne diese Sicht wäre der
        // Verlust eines Resets unsichtbar — und ein nicht zurückgesetzter Puffer bedeutet
        // in Worker-Laufzeiten, dass Events des vorigen Requests im nächsten Frame landen.
        if ($container->hasDefinition('services_resetter')) {
            $resetter = $container->getDefinition('services_resetter');
            /** @var array<array-key, mixed> $methods */
            $methods = $resetter->getArgument(1);

            foreach ($methods as $id => $method) {
                if (self::belongsToBundle((string) $id)) {
                    $fingerprint['resettable'][(string) $id] = self::normalizeValue($method);
                }
            }
        }

        // Auch die Parameter: sie tragen die gesamte aufgelöste Konfiguration, und ein
        // Refactor der Verdrahtung darf sie nicht anfassen.
        foreach ($container->getParameterBag()->all() as $name => $value) {
            if (str_starts_with($name, 'ids_sensor.')) {
                $fingerprint['parameters'][$name] = self::normalizeValue($value);
            }
        }

        ksort($fingerprint);

        foreach ($fingerprint as &$section) {
            ksort($section);
        }

        $directory = \dirname($this->file);

        if (!is_dir($directory)) {
            @mkdir($directory, 0o770, true);
        }

        file_put_contents(
            $this->file,
            json_encode($fingerprint, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Definition $definition): array
    {
        $tags = $definition->getTags();
        ksort($tags);

        $described = [
            'class' => $definition->getClass(),
            'public' => $definition->isPublic(),
            // shared und lazy sind verhaltensrelevant: der Puffer ist zustandsbehaftet pro
            // Request, ein versehentliches `shared: false` wäre Datenverlust ohne Fehler.
            'shared' => $definition->isShared(),
            'lazy' => $definition->isLazy(),
            'abstract' => $definition->isAbstract(),
            'synthetic' => $definition->isSynthetic(),
            'arguments' => array_map(self::normalizeValue(...), $definition->getArguments()),
            'tags' => $tags,
        ];

        if (null !== $definition->getFactory()) {
            $described['factory'] = self::normalizeValue($definition->getFactory());
        }

        if ([] !== $definition->getMethodCalls()) {
            $described['calls'] = array_map(
                static fn (array $call): array => [$call[0], array_map(self::normalizeValue(...), $call[1])],
                $definition->getMethodCalls(),
            );
        }

        return $described;
    }

    /**
     * Übersetzt Argumente in vergleichbare Zeichenketten.
     *
     * Objekte wie Reference oder TaggedIteratorArgument haben keine stabile
     * JSON-Darstellung; ohne diese Übersetzung verglichen man Objekt-Identitäten statt
     * Inhalte.
     */
    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof Reference) {
            return \sprintf('@%s%s', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE === $value->getInvalidBehavior() ? '?' : '', (string) $value);
        }

        if ($value instanceof TaggedIteratorArgument) {
            // Auch die aufgelöste Liste, nicht nur den Tagnamen.
            //
            // ResolveTaggedIteratorArgumentPass hat zu diesem Zeitpunkt bereits die sortierte
            // Liste eingesetzt. Gäbe der Abdruck nur den Tagnamen aus, bliebe eine geänderte
            // REIHENFOLGE der Normalisierer unsichtbar — und die ist Semantik:
            // EventFlusher::normalizerFor() nimmt den ersten supports()-Treffer.
            return [
                '!tagged_iterator' => $value->getTag(),
                'resolved' => array_map(self::normalizeValue(...), $value->getValues()),
            ];
        }

        if ($value instanceof IteratorArgument) {
            return ['!iterator' => array_map(self::normalizeValue(...), $value->getValues())];
        }

        if ($value instanceof ServiceClosureArgument) {
            return ['!service_closure' => array_map(self::normalizeValue(...), $value->getValues())];
        }

        if ($value instanceof Definition) {
            // Inline-Definition (`!service` im YAML).
            return ['!inline' => self::describe($value)];
        }

        if ($value instanceof AbstractArgument) {
            return '!abstract';
        }

        if (\is_array($value)) {
            return array_map(self::normalizeValue(...), $value);
        }

        if (\is_object($value)) {
            return get_debug_type($value);
        }

        return $value;
    }

    private static function belongsToBundle(string $id): bool
    {
        if (str_starts_with($id, self::EXCLUDED)) {
            return false;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($id, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

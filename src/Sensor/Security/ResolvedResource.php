<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Security;

/**
 * Die aufgelöste Ressource eines Voter-Subjekts, in ihre beiden Bestandteile zerlegt.
 *
 * WOZU DIE ZERLEGUNG
 *
 * `payload.resource` trägt nach Konzept 3.1.2 einen zusammengesetzten Identifier
 * („Order#42"). Für die Anzeige reicht das; für die Erkennung nicht. Die Regeln B7, P1
 * und P2 vergleichen „numerisch benachbarte Ressourcen-Identifier desselben Typs" — aus
 * einem kombinierten String ist dieser Vergleich nur über Zeichenkettenanalyse zu haben,
 * und der Collector müsste sie für jede Zeile erneut fahren (offener Punkt O2).
 *
 * WARUM EIN WERTOBJEKT UND NICHT ZWEI AUFRUFE
 *
 * `resource`, `resource_type` und `resource_id` beschreiben DASSELBE Subjekt. Zwei
 * getrennte Auflösungen könnten auseinanderlaufen — bei einem Doctrine-Proxy, dessen
 * `getId()` beim zweiten Aufruf plötzlich lädt, oder schlicht, wenn jemand später nur
 * eine der beiden Stellen anfasst. Ein Objekt, ein Durchlauf, drei Felder.
 *
 * @internal
 */
final class ResolvedResource
{
    /**
     * @param string|null $type der Typname in der Schreibweise der QUELLE (`Order`) —
     *                          {@see typeForWire()} liefert die des Drahtformats
     * @param string|null $id   die Kennung innerhalb des Typs
     */
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?string $id = null,
    ) {
    }

    /**
     * Der zusammengesetzte Identifier für `payload.resource` — unverändert in der
     * Form, die Konzept 3.1.2 zeigt.
     *
     * Die Groß-/Kleinschreibung bleibt hier, wie sie kam: Das Feld ist der Beleg für
     * einen Menschen, der einen Vorfall liest, und `Order#42` benennt die Klasse, die
     * er im Quellcode findet.
     */
    public function identifier(): ?string
    {
        if (null === $this->type) {
            return $this->id;
        }

        return null === $this->id ? $this->type : $this->type.'#'.$this->id;
    }

    /**
     * Der Typ für `payload.resource_type` — kleingeschrieben.
     *
     * Der Collector GRUPPIERT danach. Zwei Schreibweisen wären zwei Ressourcentypen,
     * und die Nachbarschaftsregel sähe die Hälfte der Zugriffe nicht. Kleinschreibung
     * ist dabei die Form, in der auch die Kernel-Ebene ihren Typ liefert (Routennamen
     * sind konventionell klein), also die einzige, die beide Quellen erreichen können.
     */
    public function typeForWire(): ?string
    {
        return null === $this->type ? null : mb_strtolower($this->type);
    }

    public static function none(): self
    {
        return new self();
    }
}

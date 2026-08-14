<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup\Cleaner;
use ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup\Rules;
use Symfony\Component\Yaml\Yaml;

/**
 * Ein Cleaner mit der AUSGELIEFERTEN Redaktionsliste.
 *
 * Bewusst nicht mit einer im Test erfundenen Liste: dann prüften die Tests eine Liste,
 * die niemand ausliefert. Die einzige Fassung, die zählt, ist die in
 * config/payload_confidentiality_cleanup.dist.yaml — sie ist es, die in fremden Anwendungen wirkt.
 */
final class TestCleaner
{
    public static function default(): Cleaner
    {
        return new Cleaner(self::rules());
    }

    public static function rules(): Rules
    {
        /** @var array{version: int, headers: list<string>, parameters: list<string>} $parsed */
        $parsed = Yaml::parseFile(self::path());

        return Rules::fromLists($parsed['version'], $parsed['headers'], $parsed['parameters']);
    }

    public static function path(): string
    {
        return \dirname(__DIR__, 2).'/config/payload_confidentiality_cleanup.dist.yaml';
    }
}

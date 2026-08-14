<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // Nur @Symfony, nicht zusätzlich @PER-CS2.0: die beiden widersprechen sich
        // bei arrow functions (`fn ()` gegen `fn()`), und das Bundle lebt im
        // Symfony-Ökosystem.
        '@Symfony' => true,
        'declare_strict_types' => true,
        // Globale Klassen bleiben inline (\DateTimeImmutable statt use-Import) —
        // das ist die Konvention von Symfony selbst.
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        // Im Request-Pfad zählt jede Mikrosekunde: der Backslash erlaubt der
        // PHP-Engine, die Funktion direkt aufzulösen, statt zuerst im aktuellen
        // Namespace zu suchen.
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
        'native_constant_invocation' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_to_comment' => false,
        // PHPUnit führt Metadaten im Docblock seit Version 10 als veraltet und
        // entfernt sie in Version 12. Die Regel ist weniger für den einmaligen Umbau
        // da als dafür, den Zustand zu halten: eine neu geschriebene @dataProvider
        // wird beim nächsten Lauf umgeschrieben, statt sich einzunisten.
        'php_unit_attributes' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile('.php-cs-fixer.cache');

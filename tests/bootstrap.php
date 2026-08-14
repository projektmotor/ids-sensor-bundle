<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
 * Räumt die Container-Caches der Integrationstests EINMAL vor dem Lauf auf.
 *
 * Bewusst nicht in tearDown(): eine einmal geladene Containerklasse bleibt für die
 * Dauer des PHP-Prozesses geladen und verweist auf Dateien in ihrem
 * Cache-Verzeichnis. Wird das Verzeichnis zwischen zwei Tests gelöscht, scheitert der
 * nächste Zugriff auf eine erst bei Bedarf erzeugte Datei — etwa einen Lazy-Proxy —
 * mit „Failed opening required ...Ghost....php".
 */
$cacheRoot = sys_get_temp_dir().'/ids-sensor-tests';

if (is_dir($cacheRoot)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($cacheRoot);
}

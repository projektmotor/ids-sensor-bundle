<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Kernel;

use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Leitet aus einem beliebigen Throwable den HTTP-Statuscode ab.
 *
 * Konzept 2.1.1 und 3.1.1 verlangen für kernel.exception einen „abgeleiteten
 * HTTP-Statuscode". Symfonys eigene Ableitung (FlattenException) kennt nur
 * HttpExceptionInterface, RequestExceptionInterface und sonst 500. Das genügt hier
 * NICHT, und der Grund liegt in der Listener-Reihenfolge:
 *
 * Der Kernel-Sensor hört kernel.exception bei Priorität 1024 ab — bewusst über dem
 * Security-ExceptionListener bei Priorität 1, damit die Originalklasse der Exception
 * sichtbar bleibt und auch Exceptions erfasst werden, die spätere Listener
 * verschlucken. Zu diesem Zeitpunkt ist eine AccessDeniedException aber noch NICHT
 * in eine AccessDeniedHttpException umgewandelt: sie implementiert kein
 * HttpExceptionInterface und würde als 500 gelten.
 *
 * Ein abgelehnter Zugriff wäre damit als event_severity = critical gemeldet — also
 * als Serverfehler. Genau das schließt Konzept 2.2.1 aus: critical ist Serverfehlern
 * vorbehalten, nicht abgelehnten oder nicht gefundenen Zugriffen.
 *
 * Deshalb wird die Exception-Kette durchlaufen, analog zu dem, was Symfonys
 * ExceptionListener selbst tut.
 *
 * @internal
 */
final class HttpStatusResolver
{
    /**
     * Begrenzt, wie tief in getPrevious() abgestiegen wird. Verhindert Endlosläufe
     * bei zyklisch verketteten Exceptions und begrenzt die Kosten.
     */
    public const MAX_CHAIN_DEPTH = 5;

    public const FALLBACK_STATUS = 500;

    /**
     * FQCN-Namen statt Imports, weil symfony/security-core nur eine
     * Entwicklungsabhängigkeit ist: das Bundle muss auch in Anwendungen ohne
     * SecurityBundle installierbar sein. instanceof gegen eine nicht vorhandene
     * Klasse ergibt false, ohne einen Fehler auszulösen.
     */
    private const ACCESS_DENIED = 'Symfony\Component\Security\Core\Exception\AccessDeniedException';

    private const AUTHENTICATION_EXCEPTION = 'Symfony\Component\Security\Core\Exception\AuthenticationException';

    public function resolve(\Throwable $throwable): int
    {
        $current = $throwable;

        for ($depth = 0; $depth < self::MAX_CHAIN_DEPTH && null !== $current; ++$depth) {
            $status = self::statusOf($current);

            if (null !== $status) {
                return $status;
            }

            $current = $current->getPrevious();
        }

        return self::FALLBACK_STATUS;
    }

    private static function statusOf(\Throwable $throwable): ?int
    {
        if ($throwable instanceof HttpExceptionInterface) {
            return $throwable->getStatusCode();
        }

        // Über lokale Variablen, weil instanceof eine Klassenkonstante nicht
        // unmittelbar als rechten Operanden akzeptiert.
        $accessDenied = self::ACCESS_DENIED;
        if ($throwable instanceof $accessDenied) {
            return 403;
        }

        $authenticationException = self::AUTHENTICATION_EXCEPTION;
        if ($throwable instanceof $authenticationException) {
            return 401;
        }

        if ($throwable instanceof RequestExceptionInterface) {
            return 400;
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TestController
{
    public function __construct(
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {
    }

    public function ok(): Response
    {
        return new Response('in Ordnung');
    }

    public function boom(): Response
    {
        throw new \RuntimeException('etwas ist kaputt');
    }

    public function notFound(): Response
    {
        throw new NotFoundHttpException('nicht gefunden');
    }

    /**
     * Wirft die ROHE AccessDeniedException — nicht die HTTP-Variante. Genau der Fall,
     * für den HttpStatusResolver existiert: bei unserer Listener-Priorität ist sie
     * noch nicht in eine AccessDeniedHttpException umgewandelt.
     */
    public function denied(): Response
    {
        throw new AccessDeniedException('kein Zugriff');
    }

    /**
     * Prüft mehrfach dieselben und verschiedene Rechte.
     *
     * Der doppelte Aufruf mit identischen Argumenten ist Absicht: er belegt, dass der
     * AccessDecisionSensor dedupliziert und nicht pro isGranted() ein Event erzeugt.
     */
    public function decide(): Response
    {
        if (null === $this->authorizationChecker) {
            throw new \LogicException('Ohne SecurityBundle nicht aufrufbar.');
        }

        $order = new TestOrder(43);

        $results = [
            'role_user' => $this->authorizationChecker->isGranted('ROLE_USER'),
            'view_order' => $this->authorizationChecker->isGranted('VIEW', $order),
            'view_order_again' => $this->authorizationChecker->isGranted('VIEW', $order),
            'role_admin' => $this->authorizationChecker->isGranted('ROLE_ADMIN'),
        ];

        return new Response(json_encode($results, \JSON_THROW_ON_ERROR));
    }

    /**
     * Eine öffentlich cachebare Antwort.
     *
     * Der Prüfstein dafür, dass der Sensor die Antwort der Anwendung nicht verändert:
     * greift er auf den GETRACKTEN Token-Speicher zu, erhöht das den Session-Usage-Index,
     * und Symfonys AbstractSessionListener macht die Antwort daraufhin `private`.
     */
    public function cacheable(): Response
    {
        $response = new Response('cachebar');
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }

    /**
     * Ohne Content-Length: response_size_bytes muss hier null sein und nicht 0.
     */
    public function streamed(): StreamedResponse
    {
        return new StreamedResponse(static function (): void {
            echo 'gestreamt';
        });
    }
}

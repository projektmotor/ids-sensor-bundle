<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Controller\ControllerReference;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Fragment\FragmentHandler;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TestController
{
    public function __construct(
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
        private readonly ?FragmentHandler $fragmentHandler = null,
    ) {
    }

    public function ok(): Response
    {
        return new Response('in Ordnung');
    }

    /**
     * Löst einen echten SUB-REQUEST aus — der Fall, den `layers.kernel.sub_requests`
     * regelt und für den es bislang keinen einzigen Test gab.
     *
     * Gerendert wird über den FragmentHandler, weil nur er den Weg nimmt, den auch
     * Twigs `render()` und ESI nehmen: `Request::duplicate()` im
     * `InlineFragmentRenderer`, also ein Sub-Request mit einer KOPIE des Elternpfades.
     * Genau daraus folgt die Vorgabe `exceptions_only` — sechs fast identische
     * Request-Events je Seite wären die Fehlalarmquelle aus Konzept 2.2.1.
     */
    public function mitFragment(): Response
    {
        return new Response('Rahmen: '.$this->fragmentHandler?->render(
            new ControllerReference(self::class.'::fragment'),
        ));
    }

    public function fragment(): Response
    {
        return new Response('Fragment');
    }

    /**
     * Ein Fragment, das wirft.
     *
     * `InlineFragmentRenderer` verschluckt die Exception bei `ignore_errors`
     * vollständig — sie existiert damit in keinem anderen Event. Genau das will ein IDS
     * sehen, und genau deshalb lässt die Vorgabe Sub-Request-Exceptions durch.
     */
    public function fragmentBoom(): Response
    {
        throw new \RuntimeException('das Fragment ist kaputt');
    }

    public function mitKaputtemFragment(): Response
    {
        // ignore_errors ausdrücklich: In Produktion ist das die Vorgabe
        // (`!$this->debug`), und genau dann verschluckt der InlineFragmentRenderer die
        // Exception vollständig — sie existiert dann in keinem anderen Event.
        return new Response('Rahmen: '.$this->fragmentHandler?->render(
            new ControllerReference(self::class.'::fragmentBoom'),
            'inline',
            ['ignore_errors' => true],
        ));
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
    public function denied(Request $request): Response
    {
        // Die Meldung trägt die angefragte URI samt Query — die verbreitetste Form, in
        // der ein Geheimnis in `payload.exception_message` landet. Das Feld reist bei
        // JEDER Stufe mit, anders als raw.
        throw new AccessDeniedException('kein Zugriff auf '.$request->getRequestUri());
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

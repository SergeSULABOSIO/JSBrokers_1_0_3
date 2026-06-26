<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Point de contact du « heartbeat » CRM : le ping Stimulus l'appelle pendant que
 * l'agent travaille. La réponse est immédiate (204) ; la routine quotidienne est
 * exécutée, si due, après l'envoi de la réponse par CrmHeartbeatSubscriber
 * (kernel.terminate). Aucun cron requis.
 */
#[Route('/console/crm/heartbeat', name: 'console.crm.heartbeat')]
#[IsGranted('ROLE_ADMIN')]
class CrmHeartbeatController extends AbstractConsoleController
{
    public function __invoke(): Response
    {
        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}

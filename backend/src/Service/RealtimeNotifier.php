<?php

namespace App\Service;

use App\Entity\Ticket;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RealtimeNotifier
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ParameterBagInterface $params,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Envoie un événement temps réel au hub WebSocket.
     * Format attendu par le hub : { type: "...", data: {...} }
     */
    public function notify(
        string $event,
        Ticket $ticket,
        array $extra = []
    ): bool {
        $hubUrl = $this->params->get('realtime_hub_url');

        $payload = [
            'type' => $event,
            'data' => array_merge([
                'ticketId'            => $ticket->getId(),
                'reference'           => $ticket->getReferenceCommande(),
                'montant'             => $ticket->getMontantARembourser(),
                'devise'              => $ticket->getDevise() ?: 'EUR',
                'statut'              => $ticket->getStatut(),
                'statutRemboursement' => $ticket->getStatutRemboursement(),
                'typeRemboursement'   => $ticket->getTypeRemboursement(),
                'user'                => $ticket->getUser()?->getEmail(),
                'date'                => $ticket->getDateCommande()?->format('d/m/Y'),
            ], $extra),
        ];

        try {
            $secret = $this->params->has('realtime_hub_secret')
                ? $this->params->get('realtime_hub_secret')
                : null;

            $response = $this->httpClient->request(
                'POST',
                rtrim($hubUrl, '/') . '/broadcast',
                [
                    'json'    => $payload,
                    'timeout' => 3,
                    'headers' => $secret ? ['X-Ws-Secret' => $secret] : [],
                ]
            );

            $statusCode = $response->getStatusCode();
            $ok = $statusCode >= 200 && $statusCode < 300;

            if ($ok) {
                $this->logger->info(sprintf(
                    '[realtime] %s diffusé pour le ticket #%d (HTTP %d)',
                    $event,
                    $ticket->getId(),
                    $statusCode
                ));
            } else {
                $this->logger->warning(sprintf(
                    '[realtime] Échec diffusion %s pour le ticket #%d : HTTP %d — %s',
                    $event,
                    $ticket->getId(),
                    $statusCode,
                    $response->getContent(false)
                ));
            }

            return $ok;
        } catch (\Throwable $e) {
            // Le temps réel ne doit jamais empêcher
            // une opération métier de fonctionner.
            $this->logger->error(sprintf(
                '[realtime] Exception lors de la diffusion %s pour le ticket #%d : %s',
                $event,
                $ticket->getId(),
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Notification générique d'un changement de ticket.
     */
    public function ticketUpdated(
        string $event,
        Ticket $ticket,
        array $extra = []
    ): bool {
        return $this->notify($event, $ticket, $extra);
    }
}
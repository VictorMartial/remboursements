<?php

namespace App\Service;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class MercurePublisher
{
    public function __construct(
        private HubInterface $hub
    ) {}

    /**
     * Publie une mise à jour sur un topic Mercure
     */
    public function publish(string $topic, array $data): void
    {
        $update = new Update(
            $topic,
            json_encode($data)
        );

        $this->hub->publish($update);
    }

    /**
     * Notifie qu'un ticket a changé de statut
     */
    public function publishTicketUpdate(int $ticketId, string $statut, array $extra = []): void
    {
        $this->publish("tickets/{$ticketId}", [
            'type' => 'ticket_update',
            'ticketId' => $ticketId,
            'statut' => $statut,
            ...$extra,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    /**
     * Notifie le dashboard interlocuteur (nouveaux tickets, RIB, etc.)
     */
    public function publishInterlocuteurUpdate(string $action, array $data = []): void
    {
        $this->publish('interlocuteur/dashboard', [
            'type' => 'interlocuteur_update',
            'action' => $action,
            ...$data,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }
}
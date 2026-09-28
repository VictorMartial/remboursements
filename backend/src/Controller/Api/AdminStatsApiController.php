<?php

namespace App\Controller\Api;

use App\Entity\Ticket;
use App\Repository\ActivityLogRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminStatsApiController extends AbstractController
{
    #[Route('/stats', name: 'api_admin_stats', methods: ['GET'])]
    public function stats(TicketRepository $repo): JsonResponse
    {
        $monthlyStats = $repo->getMonthlyStats(6);

        // Utilisation des constantes de l'entité pour fiabilité
        $enAttenteRib = $repo->count(['statut' => Ticket::STATUT_ATTENTE_RIB]);
        $crees = $repo->count([]); // total
        $ticketsARembourser = $repo->count(['statut' => Ticket::STATUT_ATTENTE_CONFIRMATION]);
        $ticketsARenvoyer = $repo->count(['statut' => Ticket::STATUT_RIB_RECU]);
        $aevNonRecu = $repo->count(['typeRemboursement' => Ticket::TYPE_AEV]);
        $paiementMultiple = $repo->count(['typeRemboursement' => Ticket::TYPE_MULTIPLE]);

        return $this->json([
            'en_attente_rib' => $enAttenteRib,
            'crees' => $crees,
            'tickets_a_rembourser' => $ticketsARembourser,
            'tickets_a_renvoyer' => $ticketsARenvoyer,
            'aev_non_recu' => $aevNonRecu,
            'paiement_multiple' => $paiementMultiple,
            'monthlyStats' => $monthlyStats,
        ]);
    }

    #[Route('/activities', name: 'api_admin_activities', methods: ['GET'])]
    public function activities(ActivityLogRepository $logRepo): JsonResponse
    {
        $activities = $logRepo->findRecent(10);
        $data = [];
        foreach ($activities as $activity) {
            $data[] = [
                'action' => $activity->getAction(),
                'description' => $activity->getDescription(),
                'user' => $activity->getUser() ? $activity->getUser()->getEmail() : 'Système',
                'createdAt' => $activity->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }
        return $this->json($data);
    }

    #[Route('/user-stats', name: 'api_admin_user_stats', methods: ['GET'])]
    public function userStats(UserRepository $userRepo): JsonResponse
    {
        $total = $userRepo->countAll();
        $admins = $userRepo->countByRole('ROLE_ADMIN');

        return $this->json([
            'total' => $total,
            'admins' => $admins,
            'regular' => $total - $admins,
            'new_this_month' => $userRepo->countNewThisMonth(),
        ]);
    }

    #[Route('/users', name: 'api_admin_users', methods: ['GET'])]
    public function users(UserRepository $userRepo): JsonResponse
    {
        $users = $userRepo->findRecent(5);
        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'role' => $user->getRole(),
                'createdAt' => $user->getCreatedAt() ? $user->getCreatedAt()->format('Y-m-d') : null,
            ];
        }
        return $this->json($data);
    }
}
<?php

namespace App\Controller\Api;

use App\Entity\Ticket;
use App\Repository\ActivityLogRepository;
use App\Repository\AppSettingRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

    // ------------------------------------------------------------------
    // Paramètres système : Email Banque (saisie manuelle + stockage DB)
    // ------------------------------------------------------------------

    #[Route('/settings/bank-email', name: 'api_admin_bank_email', methods: ['GET'])]
    public function getBankEmail(AppSettingRepository $settingRepo): JsonResponse
    {
        // Priorité : valeur en base, sinon fallback sur .env
        $email = $settingRepo->getValue('bank_email', $_ENV['BANK_EMAIL'] ?? null);

        $setting = $settingRepo->findOneBy(['settingKey' => 'bank_email']);

        return $this->json([
            'bank_email' => $email,
            'updated_at' => $setting?->getUpdatedAt()?->format('Y-m-d H:i:s'),
            'source'     => $setting ? 'database' : 'env',
        ]);
    }

    #[Route('/settings/bank-email', name: 'api_admin_bank_email_update', methods: ['PUT', 'POST'])]
    public function updateBankEmail(Request $request, AppSettingRepository $settingRepo): JsonResponse
    {
        $data  = json_decode($request->getContent(), true) ?? [];
        $email = trim($data['bank_email'] ?? '');

        if ($email === '') {
            return $this->json([
                'success' => false,
                'message' => 'L\'email banque est obligatoire.',
            ], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json([
                'success' => false,
                'message' => 'Format d\'email invalide.',
            ], 400);
        }

        $setting = $settingRepo->setValue('bank_email', $email);

        return $this->json([
            'success'    => true,
            'message'    => 'Email banque enregistré avec succès.',
            'bank_email' => $setting->getSettingValue(),
            'updated_at' => $setting->getUpdatedAt()->format('Y-m-d H:i:s'),
            'source'     => 'database',
        ]);
    }
}

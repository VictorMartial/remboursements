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
    public function stats(TicketRepository $repo, Request $request): JsonResponse
    {
        try {
            $dateFrom = $request->query->get('date_from');
            $dateTo   = $request->query->get('date_to');
            $type     = $request->query->get('type');
            $statut   = $request->query->get('statut');

            // ✅ Stats filtrées (une seule requête, tout est agrégé côté repo)
            $filtered = $repo->getStatsFiltered($dateFrom, $dateTo, $type, $statut, 6);

            // Compatibilité : on garde les champs "historiques" attendus par le frontend
            $parStatut = $filtered['par_statut'];
            $enAttenteRib = $parStatut[Ticket::STATUT_ATTENTE_RIB] ?? 0;
            $crees        = $filtered['total'];
            $aRembourser  = $parStatut[Ticket::STATUT_ATTENTE_CONFIRMATION] ?? 0;
            $aRenvoyer    = $parStatut[Ticket::STATUT_RIB_RECU] ?? 0;
            $aevNonRecu   = $filtered['par_type'][Ticket::TYPE_AEV] ?? 0;
            $paiementMultiple = $filtered['par_type'][Ticket::TYPE_MULTIPLE] ?? 0;

            return $this->json([
                'en_attente_rib'       => $enAttenteRib,
                'crees'                => $crees,
                'tickets_a_rembourser' => $aRembourser,
                'tickets_a_renvoyer'   => $aRenvoyer,
                'aev_non_recu'         => $aevNonRecu,
                'paiement_multiple'    => $paiementMultiple,
                'monthlyStats'         => $filtered['monthly'],
                'par_statut'           => $parStatut,
                'total'                => $filtered['total'],
                'filters'              => $filtered['filters'],
            ]);
        } catch (\Exception $e) {
            error_log('Erreur /api/admin/stats : ' . $e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
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

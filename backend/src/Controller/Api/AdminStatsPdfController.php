<?php

namespace App\Controller\Api;

use App\Entity\Ticket;
use App\Repository\TicketRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminStatsPdfController extends AbstractController
{
    #[Route('/stats/export.pdf', name: 'api_admin_stats_pdf', methods: ['GET'])]
    public function exportPdf(TicketRepository $repo, Request $request): Response
    {
        // --- Récupération des filtres ---
        $dateFrom = $request->query->get('date_from');
        $dateTo   = $request->query->get('date_to');
        $type     = $request->query->get('type');
        $statut   = $request->query->get('statut');

        // --- Récupération des stats filtrées ---
        $stats = $repo->getStatsFiltered($dateFrom, $dateTo, $type, $statut, 6);

        // --- Liste détaillée (pour la table) ---
        $qb = $repo->createQueryBuilder('t')->orderBy('t.id', 'DESC');

        if ($dateFrom) {
            $qb->andWhere('t.dateCommande >= :from')
               ->setParameter('from', new \DateTimeImmutable($dateFrom . ' 00:00:00'));
        }
        if ($dateTo) {
            $qb->andWhere('t.dateCommande <= :to')
               ->setParameter('to', new \DateTimeImmutable($dateTo . ' 23:59:59'));
        }
        if ($type && in_array($type, [Ticket::TYPE_AEV, Ticket::TYPE_MULTIPLE], true)) {
            $qb->andWhere('t.typeRemboursement = :type')
               ->setParameter('type', $type);
        }
        if ($statut && $statut !== '') {
            $qb->andWhere('t.statut = :statut')
               ->setParameter('statut', $statut);
        }

        /** @var Ticket[] $tickets */
        $tickets = $qb->getQuery()->getResult();

        // --- Préparation des données pour le template ---
        $typeLabel = function (?string $t): string {
            if (!$t) return 'Tous';
            return $t === Ticket::TYPE_AEV ? 'AEV non reçu' : ($t === Ticket::TYPE_MULTIPLE ? 'Paiement multiple' : $t);
        };

        $html = $this->renderView('pdf/stats.html.twig', [
            'stats'      => $stats,
            'tickets'    => $tickets,
            'filters'    => [
                'date_from' => $dateFrom ?: 'Toutes',
                'date_to'   => $dateTo ?: 'Toutes',
                'type'      => $typeLabel($type),
                'statut'    => $statut ?: 'Tous',
            ],
            'generated_at' => (new \DateTimeImmutable())->format('d/m/Y à H:i'),
            'total'        => $stats['total'] ?? 0,
        ]);

        // --- Génération du PDF ---
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf(
            'stats_%s.pdf',
            (new \DateTimeImmutable())->format('Ymd_His')
        );

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }
}
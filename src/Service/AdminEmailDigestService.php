<?php

namespace App\Service;

use App\Repository\CommandeRepository;
use App\Repository\ReclamationRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

class AdminEmailDigestService
{
    public function __construct(
        private EmailService $emailService,
        private CommandeRepository $commandeRepository,
        private ReclamationRepository $reclamationRepository,
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Send daily digest to admins
     */
    public function sendDailyDigest(string $adminEmail, ?\DateTimeInterface $date = null): bool
    {
        if ($date === null) {
            $date = new \DateTime();
        }

        // Cast to DateTime for method calls
        $dateTime = $date instanceof \DateTime ? $date : new \DateTime($date->format('Y-m-d H:i:s'));
        $dateStart = (clone $dateTime)->setTime(0, 0, 0);
        $dateEnd = (clone $dateTime)->setTime(23, 59, 59);

        // Get orders from today
        $qb = $this->commandeRepository->createQueryBuilder('c')
            ->where('c.createdAt >= :dateStart')
            ->andWhere('c.createdAt <= :dateEnd')
            ->setParameter('dateStart', $dateStart)
            ->setParameter('dateEnd', $dateEnd);

        $orders = $qb->getQuery()->getResult();

        // Get complaints from today
        $qbRec = $this->reclamationRepository->createQueryBuilder('r')
            ->where('r.dateCreation >= :dateStart')
            ->andWhere('r.dateCreation <= :dateEnd')
            ->setParameter('dateStart', $dateStart)
            ->setParameter('dateEnd', $dateEnd);

        $complaints = $qbRec->getQuery()->getResult();

        // Get pending complaints
        $pendingComplaints = $this->reclamationRepository->createQueryBuilder('r')
            ->where('r.statut = :statut')
            ->setParameter('statut', 'En attente')
            ->getQuery()
            ->getResult();

        // Calculate statistics
        $stats = [
            'totalOrders' => count($orders),
            'totalRevenue' => array_sum(array_map(fn($o) => $o->getTotales(), $orders)),
            'totalComplaints' => count($complaints),
            'pendingComplaints' => count($pendingComplaints),
            'ordersByStatus' => $this->getOrderStatistics(),
            'complaintsByStatus' => $this->getComplaintStatistics(),
        ];

        $html = $this->twig->render('admin/email/digest.html.twig', [
            'date' => $date,
            'stats' => $stats,
            'orders' => $orders,
            'complaints' => $complaints,
            'pendingComplaints' => $pendingComplaints,
            'dashboardUrl' => $this->urlGenerator->generate('admin_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'ordersUrl' => $this->urlGenerator->generate('app_commande_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'complaintsUrl' => $this->urlGenerator->generate('admin_reclamation_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        return $this->emailService->sendEmail(
            $adminEmail,
            'Daily Digest - ' . $date->format('d/m/Y'),
            null,
            [],
            $html
        );
    }

    /**
     * Send weekly digest to admins
     */
    public function sendWeeklyDigest(string $adminEmail, ?\DateTimeInterface $date = null): bool
    {
        if ($date === null) {
            $date = new \DateTime();
        }

        // Cast to DateTime for method calls
        $dateTime = $date instanceof \DateTime ? $date : new \DateTime($date->format('Y-m-d H:i:s'));
        $dateStart = (clone $dateTime)->modify('-7 days')->setTime(0, 0, 0);
        $dateEnd = (clone $dateTime)->setTime(23, 59, 59);

        // Get orders from last 7 days
        $orders = $this->commandeRepository->createQueryBuilder('c')
            ->where('c.createdAt >= :dateStart')
            ->andWhere('c.createdAt <= :dateEnd')
            ->setParameter('dateStart', $dateStart)
            ->setParameter('dateEnd', $dateEnd)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Get complaints from last 7 days
        $complaints = $this->reclamationRepository->createQueryBuilder('r')
            ->where('r.dateCreation >= :dateStart')
            ->andWhere('r.dateCreation <= :dateEnd')
            ->setParameter('dateStart', $dateStart)
            ->setParameter('dateEnd', $dateEnd)
            ->orderBy('r.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();

        $stats = [
            'totalOrders' => count($orders),
            'totalRevenue' => array_sum(array_map(fn($o) => $o->getTotales(), $orders)),
            'totalComplaints' => count($complaints),
            'ordersByStatus' => $this->getOrderStatistics(),
            'complaintsByStatus' => $this->getComplaintStatistics(),
            'averageOrderValue' => count($orders) > 0 ? array_sum(array_map(fn($o) => $o->getTotales(), $orders)) / count($orders) : 0,
        ];

        $html = $this->twig->render('admin/email/weekly-digest.html.twig', [
            'dateStart' => $dateStart,
            'dateEnd' => $dateEnd,
            'stats' => $stats,
            'orders' => array_slice($orders, 0, 10), // Top 10
            'complaints' => array_slice($complaints, 0, 10),
            'dashboardUrl' => $this->urlGenerator->generate('admin_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        return $this->emailService->sendEmail(
            $adminEmail,
            'Weekly Digest - ' . $dateStart->format('d/m') . ' to ' . $dateEnd->format('d/m/Y'),
            null,
            [],
            $html
        );
    }

    /**
     * Get order statistics
     */
    private function getOrderStatistics(): array
    {
        $statuts = ['en_attente', 'en_cours', 'payee', 'annule', 'bloquee'];
        $stats = [];

        foreach ($statuts as $statut) {
            $stats[$statut] = $this->commandeRepository->count(['statut' => $statut]);
        }

        return $stats;
    }

    /**
     * Get complaint statistics
     */
    private function getComplaintStatistics(): array
    {
        $statuts = ['En attente', 'En cours', 'Resolu'];
        $stats = [];

        foreach ($statuts as $statut) {
            $stats[$statut] = $this->reclamationRepository->count(['statut' => $statut]);
        }

        return $stats;
    }
}

<?php

namespace App\Service;

use App\Repository\CommandeRepository;
use App\Repository\ReclamationRepository;

class ReportingService
{
    public function __construct(
        private CommandeRepository $commandeRepository,
        private ReclamationRepository $reclamationRepository,
    ) {
    }

    /**
     * Get comprehensive dashboard statistics
     */
    public function getDashboardStats(): array
    {
        return [
            'orders' => $this->getOrderStats(),
            'complaints' => $this->getComplaintStats(),
            'payments' => $this->getPaymentStats(),
            'trends' => $this->getTrends(),
        ];
    }

    /**
     * Get order statistics
     */
    public function getOrderStats(): array
    {
        $total = $this->commandeRepository->count([]);
        $pending = $this->commandeRepository->count(['statut' => 'en_attente']);
        $inProgress = $this->commandeRepository->count(['statut' => 'en_cours']);
        $paid = $this->commandeRepository->count(['statut' => 'payee']);
        $cancelled = $this->commandeRepository->count(['statut' => 'annule']);

        $totalRevenue = 0;
        $qb = $this->commandeRepository->createQueryBuilder('c')
            ->select('SUM(c.totales) as total');
        $result = $qb->getQuery()->getOneOrNullResult();
        if ($result && $result['total']) {
            $totalRevenue = (float) $result['total'];
        }

        return [
            'total' => $total,
            'pending' => $pending,
            'inProgress' => $inProgress,
            'paid' => $paid,
            'cancelled' => $cancelled,
            'totalRevenue' => $totalRevenue,
            'averageOrderValue' => $total > 0 ? $totalRevenue / $total : 0,
        ];
    }

    /**
     * Get complaint statistics
     */
    public function getComplaintStats(): array
    {
        $total = $this->reclamationRepository->count([]);
        $pending = $this->reclamationRepository->count(['statut' => 'En attente']);
        $inProgress = $this->reclamationRepository->count(['statut' => 'En cours']);
        $resolved = $this->reclamationRepository->count(['statut' => 'Resolu']);

        $resolutionRate = $total > 0 ? ($resolved / $total) * 100 : 0;

        return [
            'total' => $total,
            'pending' => $pending,
            'inProgress' => $inProgress,
            'resolved' => $resolved,
            'resolutionRate' => round($resolutionRate, 2),
        ];
    }

    /**
     * Get payment statistics
     */
    public function getPaymentStats(): array
    {
        return [
            'successful' => 0,
            'failed' => 0,
            'totalSuccessful' => 0,
            'totalFailed' => 0,
            'successRate' => 0,
        ];
    }

    /**
     * Get trend data
     */
    public function getTrends(): array
    {
        $ordersLast30Days = $this->getOrdersTrend(30);
        $complaintsLast30Days = $this->getComplaintsTrend(30);

        return [
            'orders' => $ordersLast30Days,
            'complaints' => $complaintsLast30Days,
        ];
    }

    /**
     * Get orders trend for last N days
     */
    public function getOrdersTrend(int $days = 30): array
    {
        $trend = [];
        $startDate = (new \DateTime())->modify("-{$days} days");

        for ($i = 0; $i < $days; $i++) {
            $date = (clone $startDate)->modify("+{$i} days");
            $dateStart = (clone $date)->setTime(0, 0, 0);
            $dateEnd = (clone $date)->setTime(23, 59, 59);

            $count = $this->commandeRepository->createQueryBuilder('c')
                ->select('COUNT(c.id)')
                ->where('c.createdAt >= :dateStart')
                ->andWhere('c.createdAt <= :dateEnd')
                ->setParameter('dateStart', $dateStart)
                ->setParameter('dateEnd', $dateEnd)
                ->getQuery()
                ->getSingleScalarResult();

            $trend[$date->format('Y-m-d')] = (int) $count;
        }

        return $trend;
    }

    /**
     * Get complaints trend for last N days
     */
    public function getComplaintsTrend(int $days = 30): array
    {
        $trend = [];
        $startDate = (new \DateTime())->modify("-{$days} days");

        for ($i = 0; $i < $days; $i++) {
            $date = (clone $startDate)->modify("+{$i} days");
            $dateStart = (clone $date)->setTime(0, 0, 0);
            $dateEnd = (clone $date)->setTime(23, 59, 59);

            $count = $this->reclamationRepository->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.dateCreation >= :dateStart')
                ->andWhere('r.dateCreation <= :dateEnd')
                ->setParameter('dateStart', $dateStart)
                ->setParameter('dateEnd', $dateEnd)
                ->getQuery()
                ->getSingleScalarResult();

            $trend[$date->format('Y-m-d')] = (int) $count;
        }

        return $trend;
    }

    /**
     * Get top products by sales
     */
    public function getTopProducts(int $limit = 10): array
    {
        $qb = $this->commandeRepository->createQueryBuilder('c')
            ->select('l.nomProduit, SUM(l.quantite) as totalQty, SUM(l.prix * l.quantite) as totalRevenue')
            ->leftJoin('c.ligneCommandes', 'l')
            ->groupBy('l.nomProduit')
            ->orderBy('totalRevenue', 'DESC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Get customer statistics
     */
    public function getCustomerStats(): array
    {
        $totalOrders = $this->commandeRepository->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.utilisateur) as total')
            ->getQuery()
            ->getSingleScalarResult();

        $topCustomers = $this->commandeRepository->createQueryBuilder('c')
            ->select('u.email, COUNT(c.id) as orders, SUM(c.totales) as spent')
            ->leftJoin('c.utilisateur', 'u')
            ->groupBy('u.id')
            ->orderBy('spent', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return [
            'totalCustomers' => (int) $totalOrders,
            'topCustomers' => $topCustomers,
        ];
    }

    /**
     * Generate period report
     */
    public function generatePeriodReport(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $orders = $this->commandeRepository->createQueryBuilder('c')
            ->where('c.createdAt >= :start')
            ->andWhere('c.createdAt <= :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getResult();

        $complaints = $this->reclamationRepository->createQueryBuilder('r')
            ->where('r.dateCreation >= :start')
            ->andWhere('r.dateCreation <= :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getResult();

        $totalRevenue = array_sum(array_map(fn($o) => $o->getTotales(), $orders));
        $complaintRate = count($orders) > 0 ? (count($complaints) / count($orders)) * 100 : 0;

        return [
            'period' => $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d'),
            'totalOrders' => count($orders),
            'totalRevenue' => $totalRevenue,
            'averageOrderValue' => count($orders) > 0 ? $totalRevenue / count($orders) : 0,
            'totalComplaints' => count($complaints),
            'complaintRate' => round($complaintRate, 2),
            'orders' => $orders,
            'complaints' => $complaints,
        ];
    }
}

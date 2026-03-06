<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Form\CommandeType;
use App\Repository\CommandeRepository;
use App\Service\CommandeQrCodeService;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Knp\Component\Pager\PaginatorInterface;

#[Route('/commandes')]
class CommandeController extends AbstractController
{
    #[Route('', name: 'app_commande_index', methods: ['GET'])]
    public function index(Request $request, CommandeRepository $commandeRepository, PaginatorInterface $paginator): Response
    {
        $q = $request->query->get('q');
        $statut = $request->query->get('statut') ?: null;
        $all = $request->query->getBoolean('all');
        $sort = $request->query->get('sort');
        $direction = strtoupper($request->query->get('direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = (int)$request->query->get('limit', 10) ?: 10;

        // Allowed sort columns mapping
        $allowedSorts = [
            'id' => 'c.id',
            'utilisateur' => 'u.email',
            'totales' => 'c.totales',
            'statut' => 'c.statut',
            'created_at' => 'c.createdAt',
            'createdAt' => 'c.createdAt',
        ];

        $qb = $commandeRepository->createQueryBuilder('c')
            ->leftJoin('c.utilisateur', 'u')
            ->addSelect('u');

        if ($statut) {
            $qb->andWhere('c.statut = :statut')
                ->setParameter('statut', $statut);
        }

        if ($q) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'c.totales LIKE :q',
                    'DATE_FORMAT(c.createdAt, \'%d/%m/%Y %H:%i\') LIKE :q',
                    'u.email LIKE :q'
                )
            )
            ->setParameter('q', '%' . $q . '%');
        }

        if ($sort && isset($allowedSorts[$sort])) {
            $qb->orderBy($allowedSorts[$sort], $direction);
        } else {
            $qb->orderBy('c.createdAt', 'DESC');
        }

        if (!$all) {
            $qb->setMaxResults(100);
        }

        $pagination = $paginator->paginate(
            $qb,
            $page,
            $limit
        );

        // Si c'est une requête AJAX, on ne renvoie que la liste des commandes
        if ($request->query->get('ajax')) {
            return $this->render('frontend/commande/_commandes_list.html.twig', [
                'commandes' => $pagination,
                'pagination' => $pagination,
            ]);
        }

        return $this->render('frontend/commande/index.html.twig', [
            'commandes' => $pagination,
            'pagination' => $pagination,
            'filter_q' => $q,
            'filter_statut' => $statut,
            'filter_all' => $all,
            'sort' => $sort,
            'direction' => $direction,
            'statistics' => $commandeRepository->getStatistics(),
        ]);
    }

    #[Route('/{id}', name: 'app_commande_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, CommandeRepository $commandeRepository, CommandeQrCodeService $qrCodeService): Response
    {
        // Explicitly read the ID from the URL and fetch the command
        $commande = $commandeRepository->find($id);
        
        if (!$commande) {
            throw $this->createNotFoundException('La commande n\'existe pas.');
        }

        $qrCodeDataUrl = $qrCodeService->generateQrCodeDataUrl($commande);

        // Utiliser le template frontend existant pour l'affichage détail commande
        return $this->render('frontend/commande/show.html.twig', [
            'commande' => $commande,
            'qrCode'   => $qrCodeDataUrl,
        ]);
    }

    #[Route('/new', name: 'app_commande_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $commande = new Commande();
        $form = $this->createForm(CommandeType::class, $commande);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $commande->setCreatedAt(new \DateTime());
            $entityManager->persist($commande);
            $entityManager->flush();

            $this->addFlash('success', 'Commande créée avec succès!');

            return $this->redirectToRoute('app_commande_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('commande/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_commande_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request, CommandeRepository $commandeRepository, EntityManagerInterface $entityManager): Response
    {
        // Explicitly read the ID from the URL and fetch the command
        $commande = $commandeRepository->find($id);
        
        if (!$commande) {
            throw $this->createNotFoundException('La commande n\'existe pas.');
        }

        $form = $this->createForm(CommandeType::class, $commande);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Commande modifiée avec succès!');

            return $this->redirectToRoute('app_commande_show', ['id' => $commande->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('commande/edit.html.twig', [
            'commande' => $commande,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_commande_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, CommandeRepository $commandeRepository, EntityManagerInterface $entityManager): Response
    {
        // Explicitly read the ID from the URL and fetch the command
        $commande = $commandeRepository->find($id);
        
        if (!$commande) {
            throw $this->createNotFoundException('La commande n\'existe pas.');
        }

        if ($this->isCsrfTokenValid('delete' . $commande->getId(), $request->request->get('_token'))) {
            $entityManager->remove($commande);
            $entityManager->flush();

            $this->addFlash('success', 'Commande supprimée avec succès!');
        }

        return $this->redirectToRoute('app_commande_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/export/pdf', name: 'app_commande_export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request, CommandeRepository $commandeRepository): Response
    {
        $id = $request->query->getInt('id') ?: null;
        $statut = $request->query->get('statut') ?: null;
        $all = $request->query->getBoolean('all');
        $sort = $request->query->get('sort');
        $direction = strtoupper($request->query->get('direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        // Allowed sort columns mapping
        $allowedSorts = [
            'id' => 'c.id',
            'utilisateur' => 'u.email',
            'totales' => 'c.totales',
            'statut' => 'c.statut',
            'created_at' => 'c.createdAt',
            'createdAt' => 'c.createdAt',
        ];

        // If ID provided, return single result
        if ($id !== null) {
            $found = $commandeRepository->find($id);
            $commandes = $found ? [$found] : [];
        } else {
            // Build query with optional statut filter and sorting
            $qb = $commandeRepository->createQueryBuilder('c')
                ->leftJoin('c.utilisateur', 'u')
                ->addSelect('u');

            if ($statut) {
                $qb->andWhere('c.statut = :statut')
                   ->setParameter('statut', $statut);
            }

            if ($sort && isset($allowedSorts[$sort])) {
                $qb->orderBy($allowedSorts[$sort], $direction);
            } else {
                $qb->orderBy('c.createdAt', 'DESC');
            }

            if (!$all) {
                $qb->setMaxResults(100);
            }

            $commandes = $qb->getQuery()->getResult();
        }

        // Verify GD extension is enabled (required for Dompdf to handle images)
        if (!extension_loaded('gd')) {
            throw new \RuntimeException(
                'The GD extension is required for PDF generation but is not enabled. '
                . 'Please enable the GD extension in your php.ini file.'
            );
        }

        // Render HTML content for PDF
        $html = $this->renderView('commande/export-pdf.html.twig', [
            'commandes' => $commandes,
        ]);

        // Generate PDF
        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return new Response(
            $dompdf->output(),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="commandes_' . date('Y-m-d_H-i-s') . '.pdf"',
            ]
        );
    }

    #[Route('/{id}/pdf', name: 'app_commande_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pdf(int $id, CommandeRepository $commandeRepository, CommandeQrCodeService $qrCodeService): Response
    {
        // Explicitly read the ID from the URL and fetch the command
        $commande = $commandeRepository->find($id);
        
        if (!$commande) {
            throw $this->createNotFoundException('La commande n\'existe pas.');
        }

        // Verify GD extension is enabled (required for Dompdf to handle images)
        if (!extension_loaded('gd')) {
            throw new \RuntimeException(
                'The GD extension is required for PDF generation but is not enabled. '
                . 'Please enable the GD extension in your php.ini file.'
            );
        }

        $qrCodeDataUrl = $qrCodeService->generateQrCodeDataUrl($commande);
        
        // Render HTML content for a single order
        $html = $this->renderView('commande/pdf.html.twig', [
            'commande' => $commande,
            'qrCode' => $qrCodeDataUrl,
        ]);

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response(
            $dompdf->output(),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="commande_' . $commande->getId() . '.pdf"',
            ]
        );
    }

    #[Route('/export/csv', name: 'app_commande_export_csv', methods: ['GET'])]
    public function exportCsv(Request $request, CommandeRepository $commandeRepository): Response
    {
        $statut = $request->query->get('statut') ?: null;
        $sort = $request->query->get('sort');
        $direction = strtoupper($request->query->get('direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $allowedSorts = [
            'id' => 'c.id',
            'utilisateur' => 'u.email',
            'totales' => 'c.totales',
            'statut' => 'c.statut',
            'created_at' => 'c.createdAt',
        ];

        $qb = $commandeRepository->createQueryBuilder('c')
            ->leftJoin('c.utilisateur', 'u')
            ->addSelect('u');

        if ($statut) {
            $qb->andWhere('c.statut = :statut')
               ->setParameter('statut', $statut);
        }

        if ($sort && isset($allowedSorts[$sort])) {
            $qb->orderBy($allowedSorts[$sort], $direction);
        } else {
            $qb->orderBy('c.createdAt', 'DESC');
        }

        $commandes = $qb->getQuery()->getResult();

        // Generate CSV
        $filename = 'commandes_' . date('Y-m-d_H-i-s') . '.csv';
        $handle = fopen('php://output', 'w');
        
        // Set headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        // Write BOM for Excel UTF-8 compatibility
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        // Write CSV headers
        fputcsv($handle, ['ID', 'Client', 'Email', 'Total', 'Statut', 'Date Création', 'Nombre Articles'], ',');

        // Write data
        foreach ($commandes as $commande) {
            fputcsv($handle, [
                $commande->getId(),
                $commande->getUtilisateur()->getFirstName() . ' ' . $commande->getUtilisateur()->getLastName(),
                $commande->getUtilisateur()->getEmail(),
                number_format($commande->getTotales(), 2, ',', ' '),
                ucfirst(str_replace('_', ' ', $commande->getStatut())),
                $commande->getCreatedAt()->format('d/m/Y H:i'),
                count($commande->getLignes()),
            ], ',');
        }

        fclose($handle);
        exit;
    }

    // ============= ROUTES FRONTEND =============

    #[Route('/frontend', name: 'app_frontend_commande_index')]
    public function indexFrontend(Request $request, CommandeRepository $commandeRepository, PaginatorInterface $paginator): Response
    {
        $qb = $commandeRepository->createQueryBuilder('c')
            ->leftJoin('c.utilisateur', 'u')
            ->addSelect('u')
            ->orderBy('c.createdAt', 'DESC');

        $pagination = $paginator->paginate(
            $qb,
            max(1, (int) $request->query->get('page', 1)),
            10
        );

        return $this->render('frontend/commande/index.html.twig', [
            'commandes' => $pagination,
            'pagination' => $pagination,
        ]);
    }

    #[Route('/frontend/{id}', name: 'app_frontend_commande_show', requirements: ['id' => '\d+'])]
    public function showFrontend(int $id, CommandeRepository $commandeRepository, CommandeQrCodeService $qrCodeService): Response
    {
        // Explicitly read the ID from the URL and fetch the command
        $commande = $commandeRepository->find($id);
        
        if (!$commande) {
            throw $this->createNotFoundException('La commande demandée n\'existe pas.');
        }

        $qrCodeDataUrl = $qrCodeService->generateQrCodeDataUrl($commande);
        
        return $this->render('frontend/commande/show.html.twig', [
            'commande' => $commande,
            'qrCode' => $qrCodeDataUrl,
        ]);
    }
}

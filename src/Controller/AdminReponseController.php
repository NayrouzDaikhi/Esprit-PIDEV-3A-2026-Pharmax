<?php

namespace App\Controller;

use App\Entity\Reponse;
use App\Entity\Reclamation;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/reponses')]
class AdminReponseController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private PaginatorInterface $paginator)
    {
    }

    #[Route('', name: 'admin_reponse_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = $request->query->get('search');
        $date = $request->query->get('date');
        $sortBy = $request->query->get('sortBy', 'r.dateReponse');
        $sortOrder = $request->query->get('sortOrder', 'DESC');
        $page = $request->query->get('page', 1);

        // Valider l'ordre de tri
        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
        
        // Normaliser la colonne de tri
        $mapping = [
            'id' => 'r.id',
            'contenu' => 'r.contenu',
            'reclamation' => 'rec.titre',
            'dateReponse' => 'r.dateReponse',
        ];
        if (isset($mapping[$sortBy])) {
            $sortField = $mapping[$sortBy];
        } elseif (str_contains($sortBy, '.')) {
            $sortField = $sortBy;
        } else {
            $sortField = $mapping['dateReponse'];
        }

        $qb = $this->em->getRepository(Reponse::class)
                   ->createQueryBuilder('r')
                   ->select('r')
                   ->leftJoin('r.reclamation', 'rec')
                   ->addSelect('rec');

        // 🔍 Recherche combinée Contenu + Réclamation
        if (!empty($search)) {
            $qb->andWhere('(r.contenu LIKE :search OR rec.titre LIKE :search OR CAST(rec.id AS string) LIKE :search)')
               ->setParameter('search', '%' . $search . '%');
        }

        // 📅 Filtre par date
        if (!empty($date)) {
            $dateObj = new \DateTime($date);
            $start = (clone $dateObj)->setTime(0, 0, 0);
            $end   = (clone $dateObj)->setTime(23, 59, 59);

            $qb->andWhere('r.dateReponse BETWEEN :start AND :end')
               ->setParameter('start', $start)
               ->setParameter('end', $end);
        }

        // Appliquer le tri en utilisant le champ normalisé
        $qb->orderBy($sortField, $sortOrder);

        // Paginer les résultats (10 éléments par page)
        $reponses = $this->paginator->paginate(
            $qb,
            $page,
            10
        );

        // Déterminer l'ordre opposé pour les liens de tri
        $nextSortOrder = $sortOrder === 'ASC' ? 'DESC' : 'ASC';

        return $this->render('admin/reponse/index.html.twig', [
            'reponses' => $reponses,
            'filters' => [
                'search' => $search,
                'date' => $date,
            ],
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'nextSortOrder' => $nextSortOrder,
        ]);
    }

    #[Route('/new/{reclamationId}', name: 'admin_reponse_new', methods: ['GET', 'POST'])]
    public function new(Request $request, int $reclamationId): Response
    {
        $reclamation = $this->em->getRepository(Reclamation::class)->find($reclamationId);
        
        if (!$reclamation) {
            throw $this->createNotFoundException('Réclamation non trouvée');
        }

        $reponse = new Reponse();
        $reponse->setReclamation($reclamation);

        if ($request->isMethod('POST')) {
            $contenu = trim($request->request->get('contenu', ''));
            $contenu = strip_tags($contenu);
            
            $user = $this->getUser();
            if (!$user instanceof \App\Entity\User) {
                throw $this->createAccessDeniedException('User must be authenticated');
            }

            $reponse->setContenu($contenu);
            $reponse->setUser($user);

            $this->em->persist($reponse);
            $this->em->flush();

            return $this->redirectToRoute('admin_reclamation_show', ['id' => $reclamation->getId()]);
        }

        return $this->render('admin/reponse/new.html.twig', [
            'reponse' => $reponse,
            'reclamation' => $reclamation,
        ]);
    }

    #[Route('/{id}', name: 'admin_reponse_show', methods: ['GET'])]
    public function show(Reponse $reponse): Response
    {
        return $this->render('admin/reponse/show.html.twig', [
            'reponse' => $reponse,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_reponse_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Reponse $reponse): Response
    {
        if ($request->isMethod('POST')) {
            $contenu = trim($request->request->get('contenu', ''));
            $contenu = strip_tags($contenu);

            $reponse->setContenu($contenu);

            $this->em->flush();

            return $this->redirectToRoute('admin_reclamation_show', ['id' => $reponse->getReclamation()->getId()]);
        }

        return $this->render('admin/reponse/edit.html.twig', [
            'reponse' => $reponse,
        ]);
    }

    #[Route('/{id}', name: 'admin_reponse_delete', methods: ['POST'])]
    public function delete(Request $request, Reponse $reponse): Response
    {
        $reclamationId = $reponse->getReclamation()->getId();

        if ($this->isCsrfTokenValid('delete' . $reponse->getId(), $request->request->get('_token'))) {
            $this->em->remove($reponse);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_reclamation_show', ['id' => $reclamationId]);
    }
}

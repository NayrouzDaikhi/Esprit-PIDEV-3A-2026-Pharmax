<?php

namespace App\Controller;

use App\Entity\Reclamation;
use App\Service\GeminiService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Entity\Reponse;
use App\Form\ReponseType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Psr\Log\LoggerInterface;

#[Route('/admin/reclamation')]
class AdminReclamationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private PaginatorInterface $paginator,
        private GeminiService $geminiService,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'admin_reclamation_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = $request->query->get('search', '');
        $statut = $request->query->get('statut', '');
        $date = $request->query->get('date', '');
        $sortBy = $request->query->get('sortBy', 'r.dateCreation');
        $sortOrder = $request->query->get('sortOrder', 'DESC');
        $page = $request->query->get('page', 1);

        // Valider l'ordre de tri
        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
        
        // Valider et normaliser la colonne de tri : accepter "alias.field" ou clé courte
        $mapping = [
            'id' => 'r.id',
            'titre' => 'r.titre',
            'utilisateur' => 'u.firstName',
            'statut' => 'r.statut',
            'dateCreation' => 'r.dateCreation',
        ];
        if (isset($mapping[$sortBy])) {
            $sortField = $mapping[$sortBy];
        } elseif (str_contains($sortBy, '.')) {
            $sortField = $sortBy; // already qualified
        } else {
            $sortField = $mapping['dateCreation'];
            $sortBy = $sortField;
        }

          $qb = $this->em->getRepository(Reclamation::class)->createQueryBuilder('r');
          // sélectionner explicitement les alias utilisés pour éviter les problèmes
          $qb->select('r')
              ->leftJoin('r.user', 'u')
              ->addSelect('u');

        // Recherche combinée Titre + Utilisateur
        if ($search) {
            $qb->andWhere('(r.titre LIKE :search OR u.firstName LIKE :search OR u.lastName LIKE :search OR u.email LIKE :search)')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($statut) {
            $qb->andWhere('r.statut = :statut')
               ->setParameter('statut', $statut);
        }

        if ($date) {
            $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
            if ($dateObj) {
                $dateEnd = (clone $dateObj)->modify('+1 day');
                $qb->andWhere('r.dateCreation >= :dateStart')
                   ->andWhere('r.dateCreation < :dateEnd')
                   ->setParameter('dateStart', $dateObj)
                   ->setParameter('dateEnd', $dateEnd);
            }
        }

        // Appliquer le tri en utilisant le champ normalisé
        $qb->orderBy($sortField, $sortOrder);

        // Paginer les résultats
        // Paginer en affichant 10 éléments par page (per le besoin)
        $reclamations = $this->paginator->paginate(
            $qb, // passer le QueryBuilder pour que KNP gère correctement le count
            $page,
            10 // 10 éléments par page
        );

        $filters = [
            'search' => $search,
            'statut' => $statut,
            'date' => $date,
        ];

        // statistiques générales
        $repo = $this->em->getRepository(Reclamation::class);
        $totalCount = $repo->count([]);
        $countEnAttente = $repo->count(['statut' => 'En attente']);
        $countEnCours = $repo->count(['statut' => 'En cours']);
        $countResolu = $repo->count(['statut' => 'Resolu']);

        // Déterminer l'ordre opposé pour les liens de tri
        $nextSortOrder = $sortOrder === 'ASC' ? 'DESC' : 'ASC';

        return $this->render('admin/reclamation/index.html.twig', [
            'reclamations' => $reclamations,
            'filters' => $filters,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'nextSortOrder' => $nextSortOrder,
            'stats' => [
                'total' => $totalCount,
                'en_attente' => $countEnAttente,
                'en_cours' => $countEnCours,
                'resolu' => $countResolu,
            ],
        ]);
    }

    #[Route('/export/csv', name: 'admin_reclamation_export_csv', methods: ['GET'])]
    public function exportCsv(Request $request): Response
    {
        $search = $request->query->get('search', '');
        $statut = $request->query->get('statut', '');
        $date = $request->query->get('date', '');
        $sortBy = $request->query->get('sortBy', 'r.dateCreation');
        $sortOrder = $request->query->get('sortOrder', 'DESC');

        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $mapping = [
            'id' => 'r.id',
            'titre' => 'r.titre',
            'utilisateur' => 'u.firstName',
            'statut' => 'r.statut',
            'dateCreation' => 'r.dateCreation',
        ];
        $sortField = $mapping[$sortBy] ?? $mapping['dateCreation'];

        $qb = $this->em->getRepository(Reclamation::class)->createQueryBuilder('r')
            ->select('r')
            ->leftJoin('r.user', 'u')
            ->addSelect('u');

        if ($search) {
            $qb->andWhere('(r.titre LIKE :search OR u.firstName LIKE :search OR u.lastName LIKE :search OR u.email LIKE :search)')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($statut) {
            $qb->andWhere('r.statut = :statut')
               ->setParameter('statut', $statut);
        }

        if ($date) {
            $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
            if ($dateObj) {
                $dateEnd = (clone $dateObj)->modify('+1 day');
                $qb->andWhere('r.dateCreation >= :dateStart')
                   ->andWhere('r.dateCreation < :dateEnd')
                   ->setParameter('dateStart', $dateObj)
                   ->setParameter('dateEnd', $dateEnd);
            }
        }

        $qb->orderBy($sortField, $sortOrder);
        $reclamations = $qb->getQuery()->getResult();

        // Generate CSV
        $filename = 'reclamations_' . date('Y-m-d_H-i-s') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $handle = fopen('php://output', 'w');
        
        // Write BOM for Excel UTF-8 compatibility
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        // Write CSV headers
        fputcsv($handle, ['ID', 'Titre', 'Client', 'Email', 'Statut', 'Date Création', 'Description'], ',');

        // Write data
        foreach ($reclamations as $reclamation) {
            fputcsv($handle, [
                $reclamation->getId(),
                $reclamation->getTitre(),
                $reclamation->getUser()->getFirstName() . ' ' . $reclamation->getUser()->getLastName(),
                $reclamation->getUser()->getEmail(),
                ucfirst(str_replace('_', ' ', $reclamation->getStatut())),
                $reclamation->getDateCreation()->format('d/m/Y H:i'),
                substr($reclamation->getDescription(), 0, 100),
            ], ',');
        }

        fclose($handle);
        exit;
    }

    #[Route('/{id}', name: 'admin_reclamation_show', methods: ['GET', 'POST'])]
    public function show(Request $request, Reclamation $reclamation): Response
    {
        $reponse = new Reponse();
        $reponseForm = $this->createForm(ReponseType::class, $reponse);
        $reponseForm->handleRequest($request);

        if ($reponseForm->isSubmitted() && $reponseForm->isValid()) {
            $user = $this->getUser();
            if (!$user instanceof \App\Entity\User) {
                throw $this->createAccessDeniedException('User must be authenticated');
            }
            
            $reponse->setReclamation($reclamation);
            $reponse->setUser($user);
            $reponse->setDateReponse(new \DateTime());

            $this->em->persist($reponse);
            $this->em->flush();

            $this->addFlash('success', 'Réponse envoyée avec succès.');

            return $this->redirectToRoute('admin_reclamation_show', ['id' => $reclamation->getId()]);
        }

        return $this->render('admin/reclamation/show.html.twig', [
            'reclamation' => $reclamation,
            'reponseForm' => $reponseForm->createView(),
        ]);
    }

    #[Route('/{id}/generate-ai-response', name: 'admin_reclamation_generate_ai_response', methods: ['POST'])]
    public function generateAiResponse(Reclamation $reclamation): JsonResponse
    {
        try {
            // Generate AI-powered response using Gemini
            $prompt = <<<PROMPT
You are a professional customer support agent for Pharmax pharmacy. A customer has submitted a complaint:

**Complaint Title:** {$reclamation->getTitre()}
**Complaint Description:** {$reclamation->getDescription()}
**Complaint Status:** {$reclamation->getStatut()}
**Submitted Date:** {$reclamation->getDateCreation()->format('d/m/Y')}

Please generate a professional, empathetic, and helpful response to resolve this complaint. The response should:
1. Acknowledge the customer's concern
2. Apologize for any inconvenience
3. Explain what actions will be taken
4. Provide a timeline for resolution
5. Offer additional support if needed

Write the response in French, professionally formatted with proper greeting and closing.
PROMPT;

            $aiResponse = $this->geminiService->generate($prompt, [
                'temperature' => 0.7,
            ]);

            // Log the generated response
            $this->logger->info('AI response generated for complaint ' . $reclamation->getId());

            // Parse and clean the response
            $response = trim($aiResponse);

            return new JsonResponse(['response' => $response]);
        } catch (\Exception $e) {
            $this->logger->error('AI response generation failed: ' . $e->getMessage());

            // Fallback to template-based response if AI fails
            return new JsonResponse([
                'response' => $this->generateFallbackResponse($reclamation),
                'warning' => 'AI service unavailable, using template response',
            ]);
        }
    }

    #[Route('/{id}/edit', name: 'admin_reclamation_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Reclamation $reclamation): Response
    {
        if ($request->isMethod('POST')) {
            $reclamation->setStatut($request->request->get('statut'));

            $this->em->flush();

            return $this->redirectToRoute('admin_reclamation_show', ['id' => $reclamation->getId()]);
        }

        return $this->render('admin/reclamation/edit.html.twig', [
            'reclamation' => $reclamation,
        ]);
    }

    #[Route('/{id}', name: 'admin_reclamation_delete', methods: ['POST'])]
    public function delete(Request $request, Reclamation $reclamation): Response
    {
        if ($this->isCsrfTokenValid('delete' . $reclamation->getId(), $request->request->get('_token'))) {
            $this->em->remove($reclamation);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_reclamation_index');
    }

    /**
     * Generate fallback response when AI is unavailable
     */
    private function generateFallbackResponse(Reclamation $reclamation): string
    {
        $shortPrompt = substr($reclamation->getDescription(), 0, 50) . (strlen($reclamation->getDescription()) > 50 ? '...' : '');

        $templates = [
            "Bonjour,\n\nSuite à votre réclamation concernant : \"{$shortPrompt}\".\n\nNous avons le plaisir de vous informer que nous avons traité votre demande et que la situation a été résolue.\n\nN'hésitez pas à nous recontacter si le problème persiste ou si vous avez d'autres questions.\n\nCordialement,\nL'équipe Pharmax",
            "Bonjour,\n\nNous vous confirmons que votre réclamation (sujet: \"{$shortPrompt}\") a bien été résolue par nos services. Nous restons à votre disposition pour toute autre demande.\n\nCordialement,\nL'équipe Pharmax",
            "Bonjour,\n\nNous avons pris les mesures nécessaires concernant votre réclamation (\"{$shortPrompt}\") et nous considérons le problème comme résolu. Merci de votre patience.\n\nCordialement,\nL'équipe Pharmax",
        ];

        return $templates[array_rand($templates)];
    }
}

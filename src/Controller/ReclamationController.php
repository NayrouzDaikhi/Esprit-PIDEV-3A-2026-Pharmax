<?php

namespace App\Controller;

use App\Entity\Reclamation;
use App\Service\EmailService;
use App\Service\GoogleTranslationService;
use App\Service\ProfanityDetectorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/reclamations')]
class ReclamationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ValidatorInterface $validator,
        private EmailService $emailService,
        private ProfanityDetectorService $profanityDetectorService,
        private GoogleTranslationService $translationService,
    )
    {
    }

    #[Route('', name: 'frontend_reclamation_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        $user = $this->getUser();
        $query = $request->query->get('query', '');

        $reclamations = $this->em->getRepository(Reclamation::class)->searchByUserAndQuery($user, $query);

        if ($request->isXmlHttpRequest()) {
            return $this->render('reclamation/_reclamation_list.html.twig', [
                'reclamations' => $reclamations,
            ]);
        }

        return $this->render('reclamation/index.html.twig', [
            'reclamations' => $reclamations,
            'search_query' => $query,
        ]);
    }

    #[Route('/new', name: 'frontend_reclamation_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        // Require user to be authenticated
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        $user = $this->getUser();
        $errors = [];
        $titre = '';
        $description = '';

        if ($request->isMethod('POST')) {
            $titre = trim($request->request->get('titre', ''));
            $description = trim($request->request->get('description', ''));

            // Validation PHP personnalisée
            $phpErrors = $this->validateReclamation($titre, $description);
            if (!empty($phpErrors)) {
                $errors = $phpErrors;
            } else {
                // Vérifier les mots inappropriés
                if ($this->profanityDetectorService->containsProfanity($titre)) {
                    $errors['titre'] = 'Le titre contient des mots inappropriés.';
                }
                if ($this->profanityDetectorService->containsProfanity($description)) {
                    $errors['description'] = 'La description contient des mots inappropriés.';
                }

                if (empty($errors)) {
                    // Sanitize les données (supprimer les balises HTML/scripts)
                    $titre = strip_tags($titre);
                    $description = strip_tags($description);

                    // Vérifier si l'utilisateur a déjà soumis une réclamation identique aujourd'hui
                    $startOfDay = (new \DateTime())->setTime(0, 0, 0);
                    $endOfDay = (new \DateTime())->setTime(23, 59, 59);

                    $qb = $this->em->getRepository(Reclamation::class)
                        ->createQueryBuilder('r')
                        ->andWhere('r.user = :user')
                        ->andWhere('r.titre = :titre')
                        ->andWhere('r.description = :description')
                        ->andWhere('r.dateCreation BETWEEN :start AND :end')
                        ->setParameter('user', $user)
                        ->setParameter('titre', $titre)
                        ->setParameter('description', $description)
                        ->setParameter('start', $startOfDay)
                        ->setParameter('end', $endOfDay)
                        ->setMaxResults(1);

                    $existing = $qb->getQuery()->getOneOrNullResult();

                    if ($existing !== null) {
                        $errors['duplicate'] = 'Vous avez déjà soumis une réclamation identique aujourd\'hui.';
                    } else {
                        // Création de l'entité
                        $reclamation = new Reclamation();
                        $reclamation->setTitre($titre);
                        $reclamation->setDescription($description);
                        $reclamation->setUser($user); // Track the logged-in user
                    }

                    // Si une nouvelle réclamation a été créée (pas de doublon), valider et persister
                    if (isset($reclamation)) {
                        // Valider l'entité avec Symfony Validator
                        $violations = $this->validator->validate($reclamation);

                        if (count($violations) > 0) {
                            // Collecter les erreurs de validation
                            foreach ($violations as $violation) {
                                $propertyPath = $violation->getPropertyPath();
                                $errors[$propertyPath] = $violation->getMessage();
                            }
                        } else {
                            // Si pas d'erreurs, créer la réclamation
                            $reclamation->setStatut('En attente');
                            $this->em->persist($reclamation);
                            $this->em->flush();

                            // Envoyer l'e-mail de confirmation (vérifier le type concret de l'utilisateur)
                            $userEmail = null;
                            if ($user instanceof \App\Entity\User && method_exists($user, 'getEmail')) {
                                $userEmail = $user->getEmail();
                            }
                            if ($userEmail) {
                                $this->emailService->sendReclamationConfirmationEmail($reclamation, $userEmail);
                            }

                            return $this->redirectToRoute('frontend_reclamation_show', ['id' => $reclamation->getId()]);
                        }
                    }
                }
            }
        }

        return $this->render('reclamation/new.html.twig', [
            'titre' => $titre,
            'description' => $description,
            'errors' => $errors,
        ]);
    }

    #[Route('/{id}', name: 'frontend_reclamation_show', methods: ['GET'])]
    public function show(Reclamation $reclamation): Response
    {
        // Require user to be authenticated
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        $user = $this->getUser();
        
        // Check if the user owns this reclamation or is an admin
        if ($reclamation->getUser() !== $user && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('You can only view your own reclamations.');
        }
        
        return $this->render('reclamation/show.html.twig', [
            'reclamation' => $reclamation,
        ]);
    }

    #[Route('/{id}/translate-reponse', name: 'frontend_reclamation_translate_reponse', methods: ['POST'])]
    public function translateReponse(Reclamation $reclamation, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if ($reclamation->getUser() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $lang = $request->request->get('lang', 'en');
        $index = (int) $request->request->get('index', 0);

        $reponses = $reclamation->getReponses();
        if (!isset($reponses[$index])) {
            return new JsonResponse(['error' => 'Réponse introuvable'], Response::HTTP_NOT_FOUND);
        }

        $contenu = $reponses[$index]->getContenu() ?? '';
        $translated = $this->translationService->translate($contenu, $lang);

        if ($translated === null) {
            return new JsonResponse(['error' => 'Traduction indisponible'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['translated' => $translated]);
    }

    #[Route('/{id}/edit', name: 'frontend_reclamation_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Reclamation $reclamation): Response
    {
        // Require user to be authenticated
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        $user = $this->getUser();
        
        // Check if the user owns this reclamation
        if ($reclamation->getUser() !== $user) {
            throw $this->createAccessDeniedException('You can only edit your own reclamations.');
        }
        
        $errors = [];
        $titre = $reclamation->getTitre() ?? '';
        $description = $reclamation->getDescription() ?? '';

        if ($request->isMethod('POST')) {
            $titre = trim($request->request->get('titre', ''));
            $description = trim($request->request->get('description', ''));

            // Validation PHP personnalisée
            $phpErrors = $this->validateReclamation($titre, $description);
            if (!empty($phpErrors)) {
                $errors = $phpErrors;
            } else {
                // Vérifier les mots inappropriés
                if ($this->profanityDetectorService->containsProfanity($titre)) {
                    $errors['titre'] = 'Le titre contient des mots inappropriés.';
                }
                if ($this->profanityDetectorService->containsProfanity($description)) {
                    $errors['description'] = 'La description contient des mots inappropriés.';
                }

                if (empty($errors)) {
                    // Sanitize les données (supprimer les balises HTML/scripts)
                    $titre = strip_tags($titre);
                    $description = strip_tags($description);

                    // Definir les valeurs sur l'entité
                    $reclamation->setTitre($titre);
                    $reclamation->setDescription($description);

                    // Valider l'entité avec Symfony Validator
                    $violations = $this->validator->validate($reclamation);

                    if (count($violations) > 0) {
                        // Collecter les erreurs de validation
                        foreach ($violations as $violation) {
                            $propertyPath = $violation->getPropertyPath();
                            $errors[$propertyPath] = $violation->getMessage();
                        }
                        // Revenir aux anciennes valeurs pour l'affichage du formulaire
                        $titre = $reclamation->getTitre();
                        $description = $reclamation->getDescription();
                    } else {
                        // Si pas d'erreurs, mettre à jour la réclamation
                        $this->em->flush();

                        return $this->redirectToRoute('frontend_reclamation_show', ['id' => $reclamation->getId()]);
                    }
                }
            }
        }

        return $this->render('reclamation/edit.html.twig', [
            'reclamation' => $reclamation,
            'titre' => $titre,
            'description' => $description,
            'errors' => $errors,
        ]);
    }

    #[Route('/{id}', name: 'frontend_reclamation_delete', methods: ['POST'])]
    public function delete(Request $request, Reclamation $reclamation): Response
    {
        // Require user to be authenticated
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        $user = $this->getUser();
        
        // Check if the user owns this reclamation
        if ($reclamation->getUser() !== $user) {
            throw $this->createAccessDeniedException('You can only delete your own reclamations.');
        }
        
        if ($this->isCsrfTokenValid('delete' . $reclamation->getId(), $request->request->get('_token'))) {
            $this->em->remove($reclamation);
            $this->em->flush();
        }

        return $this->redirectToRoute('frontend_reclamation_index');
    }

    /**
     * Valide les données de la réclamation en PHP
     */
    private function validateReclamation(string $titre, string $description): array
    {
        $errors = [];

        // Validation du titre
        if (empty($titre)) {
            $errors['titre'] = 'Le titre est obligatoire.';
        } elseif (strlen($titre) < 5) {
            $errors['titre'] = 'Le titre doit contenir au moins 5 caractères.';
        } elseif (strlen($titre) > 255) {
            $errors['titre'] = 'Le titre ne doit pas dépasser 255 caractères.';
        }

        // Validation de la description
        if (empty($description)) {
            $errors['description'] = 'La description est obligatoire.';
        } elseif (strlen($description) < 20) {
            $errors['description'] = 'La description doit contenir au moins 20 caractères.';
        } elseif (strlen($description) > 2000) {
            $errors['description'] = 'La description ne doit pas dépasser 2000 caractères.';
        }

        return $errors;
    }
}
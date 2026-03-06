<?php

namespace App\Controller;

use App\Entity\Commentaire;
use App\Entity\Produit;
use App\Repository\ArticleRepository;
use App\Repository\CommentaireRepository;
use App\Repository\ProduitRepository;
use App\Service\GoogleTranslationService;
use App\Service\CommentModerationService;
use App\Service\CommentValidationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BlogController extends AbstractController
{
    #[Route('/blog', name: 'app_blog_index', methods: ['GET'])]
    public function index(Request $request, ArticleRepository $articleRepository): Response
    {
        if ($request->headers->get('accept') === 'application/json') {
            return $this->indexJson($request, $articleRepository);
        }

        $searchQuery = $request->query->get('search', '');
        $page = max(1, (int)$request->query->get('page', 1));
        $itemsPerPage = 3;
        
        $articles = $articleRepository->findAll();
        
        // Filter out draft articles - only show published articles
        $articles = array_filter($articles, function($article) {
            return !$article->isDraft();
        });
        
        // Filter articles by search query
        if (!empty($searchQuery)) {
            $articles = array_filter($articles, function($article) use ($searchQuery) {
                $search = strtolower($searchQuery);
                return strpos(strtolower($article->getTitre()), $search) !== false ||
                       strpos(strtolower($article->getContenu()), $search) !== false;
            });
        }
        
        // Sort articles by newest first
        usort($articles, function($a, $b) {
            $dateA = $a->getDateCreation() ? $a->getDateCreation()->getTimestamp() : 0;
            $dateB = $b->getDateCreation() ? $b->getDateCreation()->getTimestamp() : 0;
            return $dateB <=> $dateA;
        });
        
        // Calculate pagination
        $totalArticles = count($articles);
        $totalPages = ceil($totalArticles / $itemsPerPage);
        $page = min($page, $totalPages);
        $page = max(1, $page);
        
        // Get articles for current page
        $startIndex = ($page - 1) * $itemsPerPage;
        $paginatedArticles = array_slice($articles, $startIndex, $itemsPerPage);
        
        return $this->render('blog/index.html.twig', [
            'articles' => $paginatedArticles,
            'searchQuery' => $searchQuery,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalArticles' => $totalArticles,
            'itemsPerPage' => $itemsPerPage,
        ]);
    }

    private function indexJson(Request $request, ArticleRepository $articleRepository)
    {
        $searchQuery = $request->query->get('search', '');
        $page = max(1, (int)$request->query->get('page', 1));
        $itemsPerPage = 3;
        
        $articles = $articleRepository->findAll();
        
        // Filter out draft articles - only show published articles
        $articles = array_filter($articles, function($article) {
            return !$article->isDraft();
        });
        
        // Filter articles by search query
        if (!empty($searchQuery)) {
            $articles = array_filter($articles, function($article) use ($searchQuery) {
                $search = strtolower($searchQuery);
                return strpos(strtolower($article->getTitre()), $search) !== false ||
                       strpos(strtolower($article->getContenu()), $search) !== false;
            });
        }
        
        // Sort articles by newest first
        usort($articles, function($a, $b) {
            $dateA = $a->getDateCreation() ? $a->getDateCreation()->getTimestamp() : 0;
            $dateB = $b->getDateCreation() ? $b->getDateCreation()->getTimestamp() : 0;
            return $dateB <=> $dateA;
        });
        
        // Calculate pagination
        $totalArticles = count($articles);
        $totalPages = ceil($totalArticles / $itemsPerPage);
        $page = min($page, $totalPages);
        $page = max(1, $page);
        
        // Get articles for current page
        $startIndex = ($page - 1) * $itemsPerPage;
        $paginatedArticles = array_slice($articles, $startIndex, $itemsPerPage);
        
        $html = $this->renderView('blog/_articles_list.html.twig', [
            'articles' => $paginatedArticles,
        ]);
        
        return new JsonResponse([
            'html' => $html,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalArticles' => $totalArticles,
        ]);
    }

    #[Route('/blog/{id<\d+>}', name: 'app_blog_show', methods: ['GET'])]
    public function show(int $id, ArticleRepository $articleRepository): Response
    {
        $article = $articleRepository->find($id);

        if (!$article || $article->isDraft()) {
            throw $this->createNotFoundException('Article not found');
        }

        // Get recommended/recent articles (excluding the current article)
        $recentArticles = $articleRepository->findBy([], ['date_creation' => 'DESC'], 5);
        $recommendedArticles = array_filter($recentArticles, function($a) use ($article) {
            return $a->getId() !== $article->getId() && !$a->isDraft();
        });
        // Limit to 4 articles for sidebar
        $recommendedArticles = array_slice($recommendedArticles, 0, 4);

        return $this->render('blog/show.html.twig', [
            'article' => $article,
            'recommendedArticles' => $recommendedArticles,
        ]);
    }

    #[Route('/blog/{id<\d+>}/translate', name: 'app_blog_translate', methods: ['POST'])]
    public function translate(
        int $id,
        ArticleRepository $articleRepository,
        GoogleTranslationService $translationService,
        EntityManagerInterface $entityManager
    ): Response
    {
        $article = $articleRepository->find($id);

        if (!$article || $article->isDraft()) {
            throw $this->createNotFoundException('Article not found');
        }

        $translated = $translationService->translate(
            $article->getContenu(),
            'en'
        );

        if ($translated) {
            $article->setContenuEn($translated);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_blog_show', [
            'id' => $article->getId()
        ]);
    }

    #[Route('/blog/{id<\d+>}/like', name: 'app_blog_like', methods: ['POST'])]
    public function likeArticle(int $id, ArticleRepository $articleRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $article = $articleRepository->find($id);

        if (!$article || $article->isDraft()) {
            return new JsonResponse(['error' => 'Article not found'], 404);
        }

        $article->incrementLikes();
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'likes' => $article->getLikes(),
        ]);
    }

    #[Route('/blog/{id<\d+>}/unlike', name: 'app_blog_unlike', methods: ['POST'])]
    public function unlikeArticle(int $id, ArticleRepository $articleRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $article = $articleRepository->find($id);

        if (!$article || $article->isDraft()) {
            return new JsonResponse(['error' => 'Article not found'], 404);
        }

        $article->decrementLikes();
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'likes' => $article->getLikes(),
        ]);
    }
    public function createComment(int $id, Request $request, ArticleRepository $articleRepository, EntityManagerInterface $entityManager): Response
    {
        // Require user to be authenticated
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException('User must be logged in');
        }
        
        $article = $articleRepository->find($id);

        if (!$article || $article->isDraft()) {
            throw $this->createNotFoundException('Article not found');
        }

        $contenu = $request->request->get('contenu', '');

        if (empty(trim($contenu))) {
            // Add error handling - redirect back with error
            return $this->redirectToRoute('app_blog_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        // Create new comment
        $commentaire = new Commentaire();
        $commentaire->setContenu($contenu);
        $commentaire->setArticle($article);
        $commentaire->setUser($user);
        $commentaire->setDatePublication(new \DateTime());
        $commentaire->setStatut('en_attente');

        $entityManager->persist($commentaire);
        $entityManager->flush();

        // Redirect back to the article page
        return $this->redirectToRoute('app_blog_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    // ========== FRONT PRODUITS ==========

    #[Route('/produits', name: 'app_front_produits', methods: ['GET'])]
    public function listProduits(Request $request, ProduitRepository $produitRepository): Response
    {
        $search = $request->query->get('search', '');
        
        if ($search) {
            // Search for products matching the query in name or description
            $produits = $produitRepository->createQueryBuilder('p')
                ->where('p.nom LIKE :search OR p.description LIKE :search')
                ->setParameter('search', '%' . $search . '%')
                ->getQuery()
                ->getResult();
        } else {
            $produits = $produitRepository->findAll();
        }

        return $this->render('blog/products.html.twig', [
            'produits' => $produits,
            'search' => $search,
        ]);
    }

    #[Route('/api/search-produits', name: 'app_api_search_produits', methods: ['GET'])]
    public function searchProduits(Request $request, ProduitRepository $produitRepository): JsonResponse
    {
        $search = $request->query->get('q', '');
        $limit = min((int)$request->query->get('limit', 6), 20); // Max 20 results

        if (strlen($search) < 2) {
            return new JsonResponse([
                'results' => [],
                'count' => 0
            ]);
        }

        // Search for products matching the query
        $produits = $produitRepository->createQueryBuilder('p')
            ->where('p.nom LIKE :search OR p.description LIKE :search')
            ->setParameter('search', '%' . $search . '%')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $results = [];
        foreach ($produits as $produit) {
            $results[] = [
                'id' => $produit->getId(),
                'nom' => $produit->getNom(),
                'prix' => $produit->getPrix(),
                'image' => $produit->getImage() ? '/uploads/images/' . $produit->getImage() : '/images/placeholder.png',
                'description' => substr($produit->getDescription(), 0, 100),
                'url' => $this->generateUrl('app_front_detail_produit', ['id' => $produit->getId()])
            ];
        }

        return new JsonResponse([
            'results' => $results,
            'count' => count($results)
        ]);
    }

    #[Route('/produit/{id}', name: 'app_front_detail_produit', methods: ['GET'])]
    public function detailProduit(string $id, ProduitRepository $produitRepository, CommentaireRepository $commentaireRepository): Response
    {
        $produit = $produitRepository->find((int)$id);

        if (!$produit) {
            throw $this->createNotFoundException('Produit not found');
        }

        // Get validated comments for the product
        $avis = $commentaireRepository->findBy(
            ['produit' => $produit, 'statut' => 'valide'],
            ['date_publication' => 'DESC']
        );

        return $this->render('blog/product_detail.html.twig', [
            'produit' => $produit,
            'avis' => $avis,
        ]);
    }

    #[Route('/produit/{id}/add-avis', name: 'app_front_add_avis', methods: ['POST'])]
    public function addAvis(
        string $id, 
        ProduitRepository $produitRepository, 
        EntityManagerInterface $entityManager, 
        Request $request,
        CommentModerationService $moderationService,
        CommentValidationService $commentValidationService
    ): JsonResponse {
        // Require user to be authenticated
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        $currentUser = $this->getUser();
        if (!$currentUser instanceof \App\Entity\User) {
            return new JsonResponse(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        
        $produit = $produitRepository->find((int)$id);

        if (!$produit) {
            return new JsonResponse(['error' => 'Produit not found'], Response::HTTP_NOT_FOUND);
        }

        $contenu = $request->request->get('contenu', '');

        // Validate content using dedicated service
        $validationErrors = $commentValidationService->validateContent($contenu);
        if (!empty($validationErrors)) {
            return new JsonResponse([
                'error' => $validationErrors[0]
            ], Response::HTTP_BAD_REQUEST);
        }

        // ✅ AI MODERATION - Analyze content for inappropriate language
        $isToxic = $moderationService->analyze($contenu);

        if ($isToxic) {
            // ❌ Content is inappropriate - block it
            return new JsonResponse([
                'success' => false,
                'warning' => 'Votre avis contient un langage inapproprié et ne peut pas être publié. Veuillez vérifier le contenu et réessayer sans langage offensant.',
                'status' => 'BLOQUE',
                'message' => 'Avis bloqué pour contenu inapproprié'
            ], Response::HTTP_FORBIDDEN);
        }

        // ✅ Content is appropriate - create comment normally
        $commentaire = new Commentaire();
        $commentaire->setContenu($contenu);
        $commentaire->setProduit($produit);
        $commentaire->setUser($currentUser);
        $commentaire->setStatut('valide');
        $commentaire->setDatePublication(new \DateTime());

        $entityManager->persist($commentaire);
        $entityManager->flush();

        // Return the comment data as JSON
        return new JsonResponse([
            'success' => true,
            'message' => 'Merci! Votre avis a été publié.',
            'avis' => [
                'id' => $commentaire->getId(),
                'contenu' => $commentaire->getContenu(),
                'date' => $commentaire->getDatePublication()->format('d M Y à H:i'),
                'statut' => $commentaire->getStatut(),
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/blog/save-article/{id<\d+>}', name: 'app_blog_save_article', methods: ['POST'])]
    public function saveArticle(int $id, ArticleRepository $articleRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $article = $articleRepository->find($id);
        if (!$article || $article->isDraft()) {
            return new JsonResponse(['error' => 'Article not found'], 404);
        }

        if (!$user->hasSavedArticle($article)) {
            $user->addSavedArticle($article);
            $entityManager->flush();
        }

        return new JsonResponse(['success' => true, 'saved' => true]);
    }

    #[Route('/blog/unsave-article/{id<\d+>}', name: 'app_blog_unsave_article', methods: ['POST'])]
    public function unsaveArticle(int $id, ArticleRepository $articleRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $article = $articleRepository->find($id);
        if (!$article) {
            return new JsonResponse(['error' => 'Article not found'], 404);
        }

        if ($user->hasSavedArticle($article)) {
            $user->removeSavedArticle($article);
            $entityManager->flush();
        }

        return new JsonResponse(['success' => true, 'saved' => false]);
    }

    #[Route('/blog/saved-articles', name: 'app_blog_saved_articles', methods: ['GET'])]
    public function savedArticles(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException('User must be logged in');
        }
        
        $savedArticles = $user->getSavedArticles()->toArray();

        // Sort articles by newest first
        usort($savedArticles, function($a, $b) {
            $dateA = $a->getDateCreation() ? $a->getDateCreation()->getTimestamp() : 0;
            $dateB = $b->getDateCreation() ? $b->getDateCreation()->getTimestamp() : 0;
            return $dateB <=> $dateA;
        });

        return $this->render('blog/saved_articles.html.twig', [
            'articles' => $savedArticles,
        ]);
    }
}

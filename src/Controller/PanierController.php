<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Livraison;
use App\Service\FraudDetectionService;
use App\Form\LivraisonType;
use App\Repository\ProduitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/panier')]
class PanierController extends AbstractController
{
    #[Route('/', name: 'app_panier_index')]
    public function index(Request $request): Response
    {
        // Require user to be authenticated
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }
        
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $panier = $session->get('panier', []);
        $total = 0;

        foreach ($panier as $item) {
            $total += $item['prix'] * $item['quantite'];
        }

        $livraison = new Livraison();
        $user = $this->getUser();
        if ($user) {
            if (method_exists($user, 'getFirstName')) {
                $livraison->setFirstName($user->getFirstName() ?? '');
            }
            if (method_exists($user, 'getLastName')) {
                $livraison->setLastName($user->getLastName() ?? '');
            }
            if (method_exists($user, 'getEmail')) {
                $livraison->setEmail($user->getEmail() ?? '');
            }
        }

        $form = $this->createForm(LivraisonType::class, $livraison, [
            'action' => $this->generateUrl('app_panier_commander'),
            'method' => 'POST',
        ]);

        return $this->render('frontend/panier/index.html.twig', [
            'panier' => $panier,
            'total' => $total,
            'livraisonForm' => $form->createView(),
        ]);
    }

    #[Route('/ajouter/{id}', name: 'app_panier_ajouter')]
    public function ajouter(int $id, Request $request, ProduitRepository $produitRepository): Response
    {
        // Require user to be authenticated
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }
        
        $produit = $produitRepository->find($id);

        if (!$produit) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Produit non trouvé!'], 404);
            }
            $this->addFlash('error', 'Produit non trouvé!');
            return $this->redirectToRoute('app_panier_index');
        }

        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $panier = $session->get('panier', []);

        // Vérifier si le produit est déjà dans le panier
        if (isset($panier[$id])) {
            $panier[$id]['quantite']++;
        } else {
            $panier[$id] = [
                'id' => $id,
                'nom' => $produit->getNom(),
                'prix' => $produit->getPrixPromo(),
                'image' => $produit->getImage(),
                'quantite' => 1,
            ];
        }

        $session->set('panier', $panier);

        $count = count($session->get('panier', []));
        $message = sprintf('Produit ajouté au panier! (%d article(s))', $count);

        // Répondre en JSON si c'est une requête AJAX
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => true,
                'message' => $message,
                'count' => $count,
            ]);
        }

        $this->addFlash('success', $message);

        // Retourner à la page précédente (referer) pour permettre d'ajouter plusieurs produits
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_front_produits');
    }

    #[Route('/retirer/{id}', name: 'app_panier_retirer')]
    public function retirer(int $id, Request $request): Response
    {
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $panier = $session->get('panier', []);

        if (isset($panier[$id])) {
            unset($panier[$id]);
            $session->set('panier', $panier);
            $this->addFlash('success', 'Produit retiré du panier!');
        }

        return $this->redirectToRoute('app_panier_index');
    }

    #[Route('/augmenter/{id}', name: 'app_panier_augmenter')]
    public function augmenter(int $id, Request $request): Response
    {
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $panier = $session->get('panier', []);

        if (isset($panier[$id])) {
            $panier[$id]['quantite']++;
            $session->set('panier', $panier);
            $this->addFlash('success', 'Quantité augmentée!');
        }

        return $this->redirectToRoute('app_panier_index');
    }

    #[Route('/diminuer/{id}', name: 'app_panier_diminuer')]
    public function diminuer(int $id, Request $request): Response
    {
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $panier = $session->get('panier', []);

        if (isset($panier[$id])) {
            if ($panier[$id]['quantite'] > 1) {
                $panier[$id]['quantite']--;
                $session->set('panier', $panier);
                $this->addFlash('success', 'Quantité diminuée!');
            } else {
                // Si quantité = 1, retirer le produit
                unset($panier[$id]);
                $session->set('panier', $panier);
                $this->addFlash('success', 'Produit retiré du panier!');
            }
        }

        return $this->redirectToRoute('app_panier_index');
    }

    #[Route('/vider', name: 'app_panier_vider')]
    public function vider(Request $request): Response
    {
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $session->set('panier', []);
        $this->addFlash('success', 'Panier vidé!');

        return $this->redirectToRoute('app_panier_index');
    }

    #[Route('/commander', name: 'app_panier_commander', methods: ['POST'])]
    public function commander(
        Request $request,
        EntityManagerInterface $em,
        ProduitRepository $produitRepository,
        MailerInterface $mailer,
        FraudDetectionService $fraudDetectionService,
        \Psr\Log\LoggerInterface $logger,
    ): Response
    {
        $logger->info('=== START COMMAND CREATION ===');
        // Require user to be authenticated
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }
        
        $user = $this->getUser();
        
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $panier = $session->get('panier', []);

        if (empty($panier)) {
            $this->addFlash('error', 'Le panier est vide!');
            return $this->redirectToRoute('app_panier_index');
        }

        $total = 0;

        $livraison = new Livraison();
        if ($user) {
            if (method_exists($user, 'getFirstName')) {
                $livraison->setFirstName($user->getFirstName() ?? '');
            }
            if (method_exists($user, 'getLastName')) {
                $livraison->setLastName($user->getLastName() ?? '');
            }
            if (method_exists($user, 'getEmail')) {
                $livraison->setEmail($user->getEmail() ?? '');
            }
        }

        $form = $this->createForm(LivraisonType::class, $livraison);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($form->isSubmitted() && !$form->isValid()) {
                $errors = [];
                foreach ($form->getErrors(true) as $error) {
                    $errors[] = $error->getMessage();
                }
                $logger->warning('Form validation failed', [
                    'errors' => $errors,
                ]);
            }
            
            foreach ($panier as $item) {
                $total += $item['prix'] * $item['quantite'];
            }

            return $this->render('frontend/panier/index.html.twig', [
                'panier' => $panier,
                'total' => $total,
                'livraisonForm' => $form->createView(),
            ]);
        }

        // Créer la commande
        $commande = new Commande();
        // Associate the order with the authenticated user
        $commande->setUtilisateur($user);
        
        $logger->info('Commande entity created', [
            'utilisateur_id' => $user->getId(),
            'commande_id_before_persist' => $commande->getId(),
        ]);

        foreach ($panier as $item) {
            $sous = $item['prix'] * $item['quantite'];
            $total += $sous;

            $ligne = new LigneCommande();
            $ligne->setNom($item['nom'])
                ->setPrix((float)$item['prix'])
                ->setQuantite((int)$item['quantite'])
                ->setSousTotal((float)$sous);

            $commande->addLigne($ligne);
        }

        // keep JSON snapshot for legacy / reporting
        $commande->setProduits($panier);
        $commande->setTotales($total);

        // Calcul du risque de fraude
        $risk = $fraudDetectionService->calculateRisk($commande);
        if ($risk >= 70) {
            // commande bloquée pour vérification par un administrateur
            $commande->setStatut('bloquee');
        } else {
            // Normal order - set status to pending
            $commande->setStatut('en_attente');
        }

        $em->persist($commande);

        // Associer et enregistrer la livraison
        $livraison->setCommande($commande);
        $em->persist($livraison);

        try {
            $em->flush();
        } catch (\Exception $e) {
            $logger->error('Flush failed', [
                'error' => $e->getMessage(),
            ]);
            $this->addFlash('error', 'Erreur lors de la création de la commande: ' . $e->getMessage());
            return $this->redirectToRoute('app_panier_index');
        }

        $commandeId = $commande->getId();
        if (!$commandeId) {
            $this->addFlash('error', 'Erreur: La commande n\'a pas pu être créée correctement.');
            return $this->redirectToRoute('app_panier_index');
        }
        
        $logger->info('Order created successfully', ['commande_id' => $commandeId]);

        if ($risk >= 70) {
            // Alerte visible côté client
            $this->addFlash('warning', sprintf(
                'Votre commande a été bloquée pour vérification (score de risque %d%%). Un administrateur va la vérifier.',
                $risk
            ));

            // Optionnel : envoyer un email à l’admin pour l’alerter
            $adminEmail = (new \Symfony\Component\Mime\Email())
                ->from('no-reply@pharmax.com')
                ->to('admin@pharmax.com')
                ->subject('Alerte fraude – commande bloquée')
                ->text(sprintf(
                    "Une commande à risque élevé a été détectée.\nUtilisateur : %s\nID commande : %d\nMontant total : %.2f EUR\nScore de risque : %d%%",
                    $user?->getEmail() ?? 'inconnu',
                    $commande->getId(),
                    $commande->getTotales() ?? 0,
                    $risk
                ));

            try {
                $mailer->send($adminEmail);
            } catch (\Exception $e) {
                $logger->error('Failed to send fraud alert email', [
                    'error' => $e->getMessage(),
                    'commande_id' => $commande->getId(),
                ]);
            }

            // Ne pas lancer le paiement, laisser la commande bloquée pour admin
            $session->set('panier', []);

            return $this->redirectToRoute('app_commande_show', ['id' => $commandeId]);
        }

        // Cas normal : confirmation par email
        $email = (new TemplatedEmail())
            ->from('no-reply@pharmax.com')
            ->to($user->getEmail())
            ->subject('Confirmation de votre commande')
            ->htmlTemplate('emails/confirmation_commande.html.twig')
            ->context([
                'user' => $user,
                'commande' => $commande,
            ]);

        try {
            $mailer->send($email);
        } catch (\Exception $e) {
            $logger->error('Failed to send confirmation email', [
                'error' => $e->getMessage(),
                'commande_id' => $commande->getId(),
            ]);
            // Don't block the checkout — the order is already saved
        }

        // Vider le panier
        $session->set('panier', []);

        // Redirect to order details page
        return $this->redirectToRoute('app_commande_show', ['id' => $commandeId]);
    }
}

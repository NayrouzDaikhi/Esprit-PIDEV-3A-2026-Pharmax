<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\ErrorCorrectionLevel;
use ParagonIE\ConstantTime\Base32;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use OTPHP\TOTP;


#[Route('/2fa')]
class TwoFactorAuthController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private LoggerInterface $logger
    )
    {
    }

    #[Route('', name: '2fa_login')]
    public function login2fa(AuthenticationUtils $authenticationUtils): Response
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/2fa_form.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'authenticationError' => $error,
        ]);
    }

    /**
     * Display the 2FA setup page.
     * Generates a new Google Authenticator secret and shows the setup form.
     */
    #[Route('/setup', name: 'app_2fa_setup', methods: ['GET'])]
    public function setup(Request $request): Response
    {
        // Require user to be authenticated
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        // Check if 2FA is already enabled
        if ($user->isTwoFactorAuthenticationEnabled()) {
            $this->addFlash('info', 'Two-factor authentication is already enabled for your account.');
            return $this->redirectToRoute('app_profile');
        }

        // Generate a new secret
        $secret = $this->generateSecret();
        
        // Store the pending secret on the user and mark setup in progress
        $user->setGoogleAuthenticatorSecretPending($secret);
        $user->set2faSetupInProgress(true);
        $this->entityManager->flush();

        return $this->render('2fa/setup.html.twig', [
            'secret' => $secret,
            'user' => $user,
        ]);
    }

    /**
     * Generate and display QR code for Google Authenticator.
     * Returns image/png response.
     */
    #[Route('/setup-qr', name: 'app_2fa_setup_qr', methods: ['GET'])]
    public function setupQrCode(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        // Get the pending secret from user (instead of session)
        $secret = $user->getGoogleAuthenticatorSecretPending();

        if (!$secret) {
            $this->logger->warning('2FA setup attempted without pending secret for user: ' . $user->getEmail());
            throw $this->createAccessDeniedException('No 2FA setup in progress. Please start the setup process.');
        }

        try {
            // Create TOTP (Time-based One-Time Password) object
            $totp = TOTP::create($secret);
            $totp->setLabel($user->getEmail());
            $totp->setIssuer('PHARMAX');

            // Get the provisioning URI (the data for QR code)
            $qrCodeUri = $totp->getProvisioningUri();

            // Generate QR code
            $qrCode = QrCode::create($qrCodeUri)
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
                ->setSize(300)
                ->setMargin(10);

            $writer = new PngWriter();
            $result = $writer->write($qrCode);

            // Return the QR code as PNG image
            return new Response(
                $result->getString(),
                Response::HTTP_OK,
                [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('QR code generation failed: ' . $e->getMessage());
            throw $this->createAccessDeniedException('Failed to generate QR code.');
        }
    }


    /**
     * Verify the 2FA code and enable 2FA for the user.
     */
    #[Route('/verify', name: 'app_2fa_verify', methods: ['POST'])]
    public function verify(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        // Get the pending secret from user entity (more reliable than session)
        $secret = $user->getGoogleAuthenticatorSecretPending();

        if (!$secret) {
            // Handle edge case: Double submission where the first request already succeeded
            if ($user->isTwoFactorAuthenticationEnabled()) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Two-factor authentication is already enabled!',
                    'redirect' => $this->generateUrl('app_profile'),
                ]);
            }

            $this->logger->warning('2FA verification attempted without pending secret for user: ' . $user->getEmail());
            return new JsonResponse([
                'success' => false,
                'message' => 'No 2FA setup in progress. Please start the setup process again.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Get the code from request
        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;

        if (!$code) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Authentication code is required.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Create TOTP object to verify the code
            $totp = TOTP::create($secret);
            
            // Verify code (with window of 1 means it will check current and adjacent 30-second windows)
            if (!$totp->verify($code)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid authentication code. Please try again.',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Code is valid - save secret to user as active 2FA
            $user->setGoogleAuthenticatorSecret($secret);
            // Clear the pending secret and reset the in-progress flag
            $user->setGoogleAuthenticatorSecretPending(null);
            $user->set2faSetupInProgress(false);
            $this->entityManager->flush();

            // Add success message
            $this->addFlash('success', 'Two-factor authentication has been enabled successfully!');

            return new JsonResponse([
                'success' => true,
                'message' => 'Two-factor authentication enabled!',
                'redirect' => $this->generateUrl('app_profile'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('2FA verification error: ' . $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'message' => 'An error occurred during verification.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Disable 2FA for the user.
     */
    #[Route('/disable', name: 'app_2fa_disable', methods: ['POST'])]
    public function disable(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        // Check CSRF token
        if (!$this->isCsrfTokenValid('disable_2fa', $request->request->get('_token'))) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid request token.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Clear the active 2FA secret and any pending setup
            $user->setGoogleAuthenticatorSecret(null);
            $user->setGoogleAuthenticatorSecretPending(null);
            $user->set2faSetupInProgress(false);
            $this->entityManager->flush();

            $this->addFlash('info', 'Two-factor authentication has been disabled.');

            return new JsonResponse([
                'success' => true,
                'message' => 'Two-factor authentication disabled.',
                'redirect' => $this->generateUrl('app_profile'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('2FA disable error: ' . $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'message' => 'An error occurred while disabling 2FA.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Generate a random base32-encoded secret for Google Authenticator.
     * 
     * Requirements for TOTP:
     * - 160 bits (20 bytes) of entropy minimum
     * - Returned as base32-encoded string
     */
    private function generateSecret(): string
    {
        // Generate 20 random bytes (160 bits)
        $randomBytes = random_bytes(20);

        // Encode as base32 (no padding for compatibility)
        $secret = Base32::encodeUpperUnpadded($randomBytes);

        return $secret;
    }
}

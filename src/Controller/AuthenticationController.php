<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\LoginFormType;
use App\Form\ProfileFormType;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthenticationController extends AbstractController
{
    /**
     * Login page - GET & POST
     */
  #[Route('/login', name: 'app_login')]
public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
{
    // If user is already authenticated, redirect to profile
    $user = $this->getUser();
    if ($user) {
        // Check if user has ROLE_USER
        // Method 1: Using in_array (if roles are stored as array)
        if (in_array('ROLE_USER', $user->getRoles(), true)) {
            return $this->redirectToRoute('app_profile');
        }
        
        // Method 2: If you have a custom method in User entity
        // if ($user->hasRole('ROLE_USER')) {
        //     return $this->redirectToRoute('app_profile');
        // }
    }

    // Get the login error if there is one
    $error = $authenticationUtils->getLastAuthenticationError();

    // Last username entered by the user
    $lastUsername = $authenticationUtils->getLastUsername();

    // Create login form with captcha
    $form = $this->createForm(LoginFormType::class);

    return $this->render('front/pages/authentication/login.html.twig', [
        'last_username' => $lastUsername,
        'error' => $error,
        'form' => $form->createView(),
    ]);
}

    /**
     * Register page - GET & POST
     */
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository
    ): Response {
        // If user is already authenticated, redirect to profile
        if ($this->getUser()) {
            return $this->redirectToRoute('app_profile');
        }

        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            // Check if email already exists
            if ($userRepository->findByEmail($data['email'])) {
                $this->addFlash('danger', 'This email is already registered. Please use another email or try to login.');
                return $this->redirectToRoute('app_register');
            }

            // Create new user
            $user = new User();
            $user->setEmail($data['email']);
            $user->setFirstName($data['firstName']);
            $user->setLastName($data['lastName']);
            $user->setStatus(User::STATUS_UNBLOCKED);
            $user->setRoles(['ROLE_USER']);
            $user->setCreatedAt(new \DateTime());
            $user->setUpdatedAt(new \DateTime());

            // Hash and set password
            $hashedPassword = $passwordHasher->hashPassword($user, $data['password']);
            $user->setPassword($hashedPassword);

            // Handle Face API registration
            $dataFaceApi = $request->request->get('dataFaceApi');
            if ($dataFaceApi) {
                $user->setDataFaceApi($dataFaceApi);
            }

            // Persist user
            $entityManager->persist($user);
            $entityManager->flush();

            // Flash only for freshly registered users
            $this->addFlash('registration_success', 'Registration successful! Please log in.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('front/pages/authentication/signup.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * User profile page (placeholder)
     */
    #[Route('/profile', name: 'app_profile')]
    public function profile(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, UserRepository $userRepository): Response
    {
        $user = $this->getUser();
        $this->denyAccessUnlessGranted('ROLE_USER');

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Email uniqueness check if changed
            $newEmail = $user->getEmail();
            $existingUser = $userRepository->findByEmail($newEmail);
            if ($existingUser && $existingUser->getId() !== $user->getId()) {
                $this->addFlash('danger', 'Email already in use.');

                return $this->redirectToRoute('app_profile');
            }

            // Handle password change
            $plainPassword = $form->get('plainPassword')->getData();
            if (!empty($plainPassword)) {
                $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);
            }

            // Handle avatar upload
            $avatarFile = $form->get('avatar')->getData();
            if ($avatarFile) {
                $projectDir = $this->getParameter('kernel.project_dir');
                $uploadDir = $projectDir . '/public/uploads/avatars';

                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }

                // Delete old avatar if it exists
                $oldAvatar = $user->getAvatar();
                if ($oldAvatar) {
                    $oldAvatarPath = $projectDir . '/public/' . $oldAvatar;
                    if (file_exists($oldAvatarPath)) {
                        @unlink($oldAvatarPath);
                    }
                }

                // Use MIME type to determine extension instead of guessing
                $mimeType = $avatarFile->getMimeType();
                $extensionMap = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                ];
                $extension = $extensionMap[$mimeType] ?? 'jpg'; // Default to jpg

                $safeFilename = 'user_' . $user->getId() . '_' . uniqid();
                $newFilename = $safeFilename . '.' . $extension;

                try {
                    $avatarFile->move($uploadDir, $newFilename);
                    $user->setAvatar('uploads/avatars/' . $newFilename);
                } catch (\Exception $e) {
                    $this->addFlash('danger', 'Failed to upload avatar. Please try again.');
                    return $this->redirectToRoute('app_profile');
                }
            }

            $user->setUpdatedAt(new \DateTime());

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Profile updated successfully.');

            return $this->redirectToRoute('app_profile');
        }

        // Determine which template to use based on user role and context
        $template = 'frontend/profile.html.twig'; // Default for normal users
        
        // Check if user is admin
        if (in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            // For admins, check the referrer to determine context
            $referer = $request->headers->get('referer', '');
            
            // If referrer contains dashboard path, use backoffice template
            if (strpos($referer, '/dashboard') !== false || strpos($referer, '/admin') !== false) {
                $template = 'back/pages/profile.html.twig';
            } else {
                // If accessed from frontoffice, use frontend template
                $template = 'frontend/profile.html.twig';
            }
        }

        return $this->render($template, [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Forgot password page
     */
    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function forgotPassword(Request $request, \Symfony\Component\Mailer\MailerInterface $mailer, \Symfony\Contracts\Translation\TranslatorInterface $translator): Response
    {
        return $this->redirectToRoute('app_forgot_password_request');
    }

    /**
     * Logout action
     */
    #[Route('/logout', name: 'app_logout')]
    public function logout(): Response
    {
        // This method will never be executed, it's handled by Symfony security
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}

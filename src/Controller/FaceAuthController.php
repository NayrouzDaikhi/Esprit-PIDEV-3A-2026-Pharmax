<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FaceAuthController extends AbstractController
{
    const THRESHOLD = 0.6; // Threshold for TinyFaceDetector euclidean distance

    #[Route(path: '/api/face-recognition', name: 'app_faceRecognition', methods: ['POST'])]
    public function faceRecognition(Request $request, UserRepository $userRepository): Response
    {
        $email = $request->request->get('email');
        $dataFaceApi = $request->request->get('dataFaceApi');

        if (!$email || !$dataFaceApi) {
            return $this->json(['isSuccessful' => false, 'message' => 'Missing data.']);
        }

        $user = $userRepository->findOneBy(['email' => $email]);
        if (!$user || !$user->getDataFaceApi()) {
            return $this->json(['isSuccessful' => false, 'message' => 'User or face data not found.']);
        }

        // Convert strings back to arrays
        $incomingDescriptor = explode(',', $dataFaceApi);
        $dbDescriptor = explode(',', $user->getDataFaceApi());

        // Calculate Euclidean distance
        $diffs = array_map(fn($x, $y) => pow($x - $y, 2), $incomingDescriptor, $dbDescriptor);
        $distance = sqrt(array_sum($diffs));

        if ($distance <= self::THRESHOLD) {
            // Success! Generate a token so the next step knows the face matched
            $tokenFaceRecognition = bin2hex(random_bytes(32));
            $request->getSession()->set('tokenFaceRecognition', $tokenFaceRecognition);
            
            return $this->json([
                'isSuccessful' => true,
                'tokenFaceRecognition' => $tokenFaceRecognition
            ]);
        }

        return $this->json(['isSuccessful' => false, 'message' => 'Face not recognized.']);
    }
}

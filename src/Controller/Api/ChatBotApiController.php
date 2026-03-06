<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/chatbot', name: 'api_chatbot_')]
class ChatBotApiController extends AbstractController
{
    public function __construct(
        private \App\Service\ChatBotService $chatBotService,
    ) {
        error_log('ChatBotApiController: Constructor called, ChatBotService is INJECTED');
    }

    /**
     * Helper to add CORS headers to response
     */
    private function addCorsHeaders(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        return $response;
    }

    /**
     * Handle preflight requests
     */
    #[Route('/ask', name: 'ask_options', methods: ['OPTIONS'])]
    #[Route('/health', name: 'health_options', methods: ['OPTIONS'])]
    public function options(): Response
    {
        $response = new Response();
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        return $response;
    }

    /**
     * Répondre à une question
     * POST /api/chatbot/ask
     */
    #[Route('/ask', name: 'ask', methods: ['POST'])]
    public function ask(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];
            
            if (!isset($data['question'])) {
                return $this->addCorsHeaders($this->json([
                    'success' => false,
                    'error' => 'Question field is required',
                    'answer' => null
                ], Response::HTTP_BAD_REQUEST));
            }

            $question = trim($data['question'] ?? '');

            // Validate question length
            if (strlen($question) < 3) {
                return $this->addCorsHeaders($this->json([
                    'success' => false,
                    'error' => 'Question must be at least 3 characters long',
                    'answer' => null
                ], Response::HTTP_BAD_REQUEST));
            }

            if (strlen($question) > 1000) {
                return $this->addCorsHeaders($this->json([
                    'success' => false,
                    'error' => 'Question must be no longer than 1000 characters',
                    'answer' => null
                ], Response::HTTP_BAD_REQUEST));
            }

            // Try to get answer from ChatBotService
            try {
                // Support both snake_case (from frontend) and camelCase (legacy)
                $articleId = $data['article_id'] ?? $data['articleId'] ?? null;
                $articleTitle = $data['article_title'] ?? $data['articleTitle'] ?? null;
                
                error_log('ChatBotApiController::ask: Received question with article_id=' . ($articleId ?? 'null') . ' article_title=' . ($articleTitle ?? 'null'));
                
                $response = $this->chatBotService->answerQuestion($question, $articleId, $articleTitle);
                error_log('ChatBotApiController::ask: Got response from ChatBotService: success=' . ($response['success'] ? 'true' : 'false'));
                
                return $this->addCorsHeaders($this->json($response));
            } catch (\Throwable $e) {
                error_log('ChatBot service error: ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
                // Fall through to fallback
            }

            // Fallback response if service fails
            return $this->addCorsHeaders($this->json([
                'success' => true,
                'answer' => $this->getFallbackAnswer($question),
                'fallback' => true,
                'message' => 'Using fallback response (AI service unavailable)'
            ]));

        } catch (\Throwable $e) {
            error_log('ChatBot API fatal error: ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
            return $this->addCorsHeaders($this->json([
                'success' => false,
                'error' => 'Server error: ' . $e->getMessage(),
                'answer' => null
            ], Response::HTTP_INTERNAL_SERVER_ERROR));
        }
    }

    /**
     * Fallback answer generator (simple keyword matching)
     */
    private function getFallbackAnswer(string $question): string
    {
        $lower = strtolower($question);
        
        // Greeting
        if (strpos($lower, 'hello') !== false || strpos($lower, 'hi') !== false || strpos($lower, 'bonjour') !== false || strpos($lower, 'salut') !== false) {
            return 'Hello! Welcome to Pharmax. How can I help you with pharmaceutical information today?';
        }
        
        // Article requests
        if (strpos($lower, 'article') !== false || strpos($lower, 'résumer') !== false || strpos($lower, 'resumer') !== false || strpos($lower, 'summary') !== false) {
            return 'To read article summaries, please browse our pharmaceutical articles section or search for specific topics. You can also ask about a specific medication or health condition.';
        }
        
        // Pricing
        if (strpos($lower, 'price') !== false || strpos($lower, 'prix') !== false || strpos($lower, 'cost') !== false || strpos($lower, 'coût') !== false) {
            return 'For pricing information, please browse our product catalog or contact our customer service team at support@pharmax.tn';
        }
        
        // Delivery/Shipping
        if (strpos($lower, 'delivery') !== false || strpos($lower, 'shipping') !== false || strpos($lower, 'livraison') !== false || strpos($lower, 'expédition') !== false) {
            return 'We offer fast delivery across Tunisia. For more details, please check our shipping policy or contact support@pharmax.tn';
        }
        
        // Products
        if (strpos($lower, 'product') !== false || strpos($lower, 'produit') !== false || strpos($lower, 'medication') !== false || strpos($lower, 'médicament') !== false) {
            return 'We have a wide range of pharmaceutical products including medications, supplements, and health products. Please visit our product page to browse our full catalog.';
        }
        
        // Symptoms/Health
        if (strpos($lower, 'symptom') !== false || strpos($lower, 'symptôme') !== false || strpos($lower, 'health') !== false || strpos($lower, 'santé') !== false || strpos($lower, 'sick') !== false || strpos($lower, 'malade') !== false) {
            return 'For health concerns, please consult with a healthcare professional. We provide pharmaceutical products and information, but cannot provide medical advice. Contact your doctor or call support@pharmax.tn';
        }
        
        // Account/Orders
        if (strpos($lower, 'order') !== false || strpos($lower, 'commande') !== false || strpos($lower, 'account') !== false || strpos($lower, 'compte') !== false || strpos($lower, 'login') !== false) {
            return 'For account and order information, please log in to your account or contact our customer service at support@pharmax.tn';
        }

        return 'Thank you for your question! I am currently learning. For detailed information, please contact our support team at support@pharmax.tn or browse our product catalog.';
    }

    /**
     * Health check endpoint for monitoring
     */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->addCorsHeaders($this->json([
            'status' => 'ok',
            'api_configured' => true,
            'message' => 'ChatBot API is running'
        ]));
    }

    /**
     * Check if Ollama is configured and accessible  
     */
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        // Check if ChatBotService has isConfigured method, otherwise assume it's configured
        $configured = method_exists($this->chatBotService, 'isConfigured') 
            ? $this->chatBotService->isConfigured() 
            : true;
        $status = $configured ? 'configured' : 'not_configured';

        return $this->addCorsHeaders($this->json([
            'status' => $status,
            'ollama_configured' => $configured,
            'service_available' => true
        ]));
    }
}

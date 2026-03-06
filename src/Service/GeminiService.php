<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiService
{
    private $client;
    private $apiKey;

    public function __construct(HttpClientInterface $client, string $apiKey)
    {
        $this->client = $client;
        $this->apiKey = $apiKey;
    }

    public function generateExpirationMessage(string $productName, int $days): string
    {
        try {
            return $this->callGeminiApi($productName, $days);
        } catch (\Exception $e) {
            // Fallback si l'API Gemini échoue
            return $this->generateFallbackMessage($productName, $days);
        }
    }

    /**
     * Generate text using Gemini API with custom prompt
     */
    public function generate(string $prompt, array $options = []): string
    {
        try {
            $response = $this->client->request(
                'POST',
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key='.$this->apiKey,
                [
                    'json' => [
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => $prompt]
                                ]
                            ]
                        ]
                    ]
                ]
            );

            $data = $response->toArray();
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } catch (\Exception $e) {
            throw new \Exception('Gemini API error: ' . $e->getMessage());
        }
    }

    private function callGeminiApi($productName, $days): string
    {
        $prompt = "Génère un message professionnel pour informer qu'un produit nommé $productName expire dans $days jours. Ajoute recommandation.";

        $response = $this->client->request(
            'POST',
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key='.$this->apiKey,
            [
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ]
                ]
            ]
        );

        $data = $response->toArray();

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? $this->generateFallbackMessage($productName, $days);
    }

    private function generateFallbackMessage($productName, $days): string
    {
        return sprintf(
            "⚠️ Notification d'expiration\n\n"
            . "Le produit '%s' expire dans %d jours.\n\n"
            . "Actions recommandées:\n"
            . "- Examiner le stock immédiatement\n"
            . "- Établir un plan de liquidation\n"
            . "- Informer les clients\n"
            . "- Vérifier la conformité réglementaire",
            $productName,
            $days
        );
    }
}

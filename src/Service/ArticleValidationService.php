<?php

namespace App\Service;

use App\Entity\Article;

/**
 * Pure business logic service for article validation.
 * No HTTP, no database — only validation rules.
 */
class ArticleValidationService
{
    public const TITLE_MIN_LENGTH = 3;
    public const TITLE_MAX_LENGTH = 255;

    /**
     * Validate article title.
     *
     * @return string[] Array of error messages (empty = valid)
     */
    public function validateTitle(string $titre): array
    {
        $errors = [];
        $trimmed = trim($titre);

        if ($trimmed === '') {
            $errors[] = 'Le titre de l\'article ne peut pas être vide.';
            return $errors;
        }

        if (mb_strlen($trimmed) < self::TITLE_MIN_LENGTH) {
            $errors[] = sprintf(
                'Le titre doit contenir au minimum %d caractères.',
                self::TITLE_MIN_LENGTH
            );
        }

        if (mb_strlen($trimmed) > self::TITLE_MAX_LENGTH) {
            $errors[] = sprintf(
                'Le titre ne doit pas dépasser %d caractères.',
                self::TITLE_MAX_LENGTH
            );
        }

        return $errors;
    }

    /**
     * Validate article content.
     *
     * @return string[] Array of error messages (empty = valid)
     */
    public function validateContent(string $contenu): array
    {
        $errors = [];

        if (trim($contenu) === '') {
            $errors[] = 'Le contenu de l\'article ne peut pas être vide.';
        }

        return $errors;
    }

    /**
     * Check whether an article has the required fields to be published.
     */
    public function canPublish(Article $article): bool
    {
        $titre = $article->getTitre();
        $contenu = $article->getContenu();

        if ($titre === null || trim($titre) === '') {
            return false;
        }

        if ($contenu === null || trim($contenu) === '') {
            return false;
        }

        // Title must meet minimum length
        if (mb_strlen(trim($titre)) < self::TITLE_MIN_LENGTH) {
            return false;
        }

        return true;
    }
}

<?php

namespace App\Service;

/**
 * Pure business logic service for comment validation.
 * No HTTP, no database — only validation rules.
 */
class CommentValidationService
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 1000;
    public const ALLOWED_STATUSES = ['valide', 'bloque', 'en_attente'];

    /**
     * Validate comment content.
     *
     * @return string[] Array of error messages (empty = valid)
     */
    public function validateContent(string $contenu): array
    {
        $errors = [];
        $trimmed = trim($contenu);

        if ($trimmed === '') {
            $errors[] = 'Le contenu du commentaire ne peut pas être vide.';
            return $errors;
        }

        if (mb_strlen($trimmed) < self::MIN_LENGTH) {
            $errors[] = sprintf(
                'Le commentaire doit contenir au minimum %d caractères.',
                self::MIN_LENGTH
            );
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            $errors[] = sprintf(
                'Le commentaire ne doit pas dépasser %d caractères.',
                self::MAX_LENGTH
            );
        }

        return $errors;
    }

    /**
     * Check whether a status value is valid.
     */
    public function isValidStatus(string $statut): bool
    {
        return in_array($statut, self::ALLOWED_STATUSES, true);
    }

    /**
     * Return the list of allowed statuses.
     *
     * @return string[]
     */
    public function getAllowedStatuses(): array
    {
        return self::ALLOWED_STATUSES;
    }
}

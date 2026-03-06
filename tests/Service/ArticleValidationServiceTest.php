<?php

namespace App\Tests\Service;

use App\Entity\Article;
use App\Service\ArticleValidationService;
use PHPUnit\Framework\TestCase;

class ArticleValidationServiceTest extends TestCase
{
    private ArticleValidationService $service;

    protected function setUp(): void
    {
        $this->service = new ArticleValidationService();
    }

    // ─── Title Validation ─────────────────────────────────────────

    public function testValidTitleReturnsNoErrors(): void
    {
        $errors = $this->service->validateTitle('Mon article de blog');
        $this->assertEmpty($errors, 'A valid title should produce no errors.');
    }

    public function testEmptyTitleReturnsError(): void
    {
        $errors = $this->service->validateTitle('');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('vide', $errors[0]);
    }

    public function testWhitespaceOnlyTitleReturnsError(): void
    {
        $errors = $this->service->validateTitle('   ');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('vide', $errors[0]);
    }

    public function testTitleTooShortReturnsError(): void
    {
        $errors = $this->service->validateTitle('AB');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('minimum', $errors[0]);
    }

    public function testTitleExactlyMinLengthIsValid(): void
    {
        $errors = $this->service->validateTitle('ABC');
        $this->assertEmpty($errors, 'Title with exactly 3 characters should be valid.');
    }

    public function testTitleTooLongReturnsError(): void
    {
        $longTitle = str_repeat('A', 256);
        $errors = $this->service->validateTitle($longTitle);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('dépasser', $errors[0]);
    }

    public function testTitleExactlyMaxLengthIsValid(): void
    {
        $title = str_repeat('A', 255);
        $errors = $this->service->validateTitle($title);
        $this->assertEmpty($errors, 'Title with exactly 255 characters should be valid.');
    }

    // ─── Content Validation ───────────────────────────────────────

    public function testValidContentReturnsNoErrors(): void
    {
        $errors = $this->service->validateContent('Contenu valide pour un article.');
        $this->assertEmpty($errors);
    }

    public function testEmptyContentReturnsError(): void
    {
        $errors = $this->service->validateContent('');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('vide', $errors[0]);
    }

    public function testWhitespaceOnlyContentReturnsError(): void
    {
        $errors = $this->service->validateContent('   ');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('vide', $errors[0]);
    }

    // ─── Publish Readiness ────────────────────────────────────────

    public function testCanPublishWithValidArticle(): void
    {
        $article = new Article();
        $article->setTitre('Titre valide');
        $article->setContenu('Contenu valide pour publication.');

        $this->assertTrue(
            $this->service->canPublish($article),
            'An article with a valid title and content should be publishable.'
        );
    }

    public function testCannotPublishWithoutTitle(): void
    {
        $article = new Article();
        // titre is null by default
        $article->setContenu('Contenu valide.');

        $this->assertFalse(
            $this->service->canPublish($article),
            'An article without a title should not be publishable.'
        );
    }

    public function testCannotPublishWithEmptyContent(): void
    {
        $article = new Article();
        $article->setTitre('Titre valide');
        // contenu is null by default

        $this->assertFalse(
            $this->service->canPublish($article),
            'An article without content should not be publishable.'
        );
    }

    public function testCannotPublishWithTitleTooShort(): void
    {
        $article = new Article();
        $article->setTitre('AB');
        $article->setContenu('Contenu valide.');

        $this->assertFalse(
            $this->service->canPublish($article),
            'An article with a title shorter than 3 chars should not be publishable.'
        );
    }
}

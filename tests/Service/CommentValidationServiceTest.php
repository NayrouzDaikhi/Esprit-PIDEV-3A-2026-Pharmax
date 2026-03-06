<?php

namespace App\Tests\Service;

use App\Service\CommentValidationService;
use PHPUnit\Framework\TestCase;

class CommentValidationServiceTest extends TestCase
{
    private CommentValidationService $service;

    protected function setUp(): void
    {
        $this->service = new CommentValidationService();
    }

    // ─── Content Validation ───────────────────────────────────────

    public function testValidContentReturnsNoErrors(): void
    {
        $errors = $this->service->validateContent('Ceci est un commentaire valide.');
        $this->assertEmpty($errors, 'A valid comment should produce no errors.');
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

    public function testContentTooShortReturnsError(): void
    {
        $errors = $this->service->validateContent('A');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('minimum', $errors[0]);
    }

    public function testContentExactlyMinLengthIsValid(): void
    {
        $errors = $this->service->validateContent('AB');
        $this->assertEmpty($errors, 'Content with exactly 2 characters should be valid.');
    }

    public function testContentTooLongReturnsError(): void
    {
        $longContent = str_repeat('a', 1001);
        $errors = $this->service->validateContent($longContent);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('dépasser', $errors[0]);
    }

    public function testContentExactlyMaxLengthIsValid(): void
    {
        $content = str_repeat('a', 1000);
        $errors = $this->service->validateContent($content);
        $this->assertEmpty($errors, 'Content with exactly 1000 characters should be valid.');
    }

    // ─── Status Validation ────────────────────────────────────────

    public function testValidStatusValide(): void
    {
        $this->assertTrue($this->service->isValidStatus('valide'));
    }

    public function testValidStatusBloque(): void
    {
        $this->assertTrue($this->service->isValidStatus('bloque'));
    }

    public function testValidStatusEnAttente(): void
    {
        $this->assertTrue($this->service->isValidStatus('en_attente'));
    }

    public function testInvalidStatusReturnsFalse(): void
    {
        $this->assertFalse($this->service->isValidStatus('unknown'));
    }

    public function testInvalidStatusCaseSensitive(): void
    {
        $this->assertFalse(
            $this->service->isValidStatus('VALIDE'),
            'Status check should be case-sensitive.'
        );
    }

    public function testGetAllowedStatusesReturnsExpectedList(): void
    {
        $statuses = $this->service->getAllowedStatuses();
        $this->assertCount(3, $statuses);
        $this->assertContains('valide', $statuses);
        $this->assertContains('bloque', $statuses);
        $this->assertContains('en_attente', $statuses);
    }
}

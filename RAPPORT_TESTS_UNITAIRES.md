# Rapport de Tests Unitaires — Pharmax
**Date :** 2026-03-05 23:51  
**Projet :** Pharmax (Symfony 6.4)  
**PHPUnit :** 9.6.34  
**PHP :** 8.1.25  

---

## 1. Résumé

| Métrique | Valeur |
|---|---|
| Tests exécutés | 27 |
| Assertions | 40 |
| Résultat | ✅ **OK** |
| Temps d'exécution | 0.018 s |
| Mémoire utilisée | 10.00 MB |

---

## 2. Services créés

### 2.1 `CommentValidationService`

**Fichier :** `src/Service/CommentValidationService.php`

Service de validation pure (sans dépendances HTTP/BDD) pour les commentaires. Règles :

| Règle | Constante | Valeur |
|---|---|---|
| Contenu non vide | — | `trim($contenu) !== ''` |
| Longueur minimale | `MIN_LENGTH` | 2 caractères |
| Longueur maximale | `MAX_LENGTH` | 1000 caractères |
| Statuts autorisés | `ALLOWED_STATUSES` | `valide`, `bloque`, `en_attente` |

### 2.2 `ArticleValidationService`

**Fichier :** `src/Service/ArticleValidationService.php`

Service de validation pure pour les articles. Règles :

| Règle | Constante | Valeur |
|---|---|---|
| Titre non vide | — | `trim($titre) !== ''` |
| Titre longueur min | `TITLE_MIN_LENGTH` | 3 caractères |
| Titre longueur max | `TITLE_MAX_LENGTH` | 255 caractères |
| Contenu non vide | — | `trim($contenu) !== ''` |
| Publication | `canPublish()` | titre + contenu valides requis |

---

## 3. Résultats détaillés des tests

### 3.1 CommentValidationServiceTest (12 tests)

**Fichier :** `tests/Service/CommentValidationServiceTest.php`

| # | Test | Résultat |
|---|---|---|
| 1 | `testValidContentReturnsNoErrors` | ✅ Pass |
| 2 | `testEmptyContentReturnsError` | ✅ Pass |
| 3 | `testWhitespaceOnlyContentReturnsError` | ✅ Pass |
| 4 | `testContentTooShortReturnsError` | ✅ Pass |
| 5 | `testContentExactlyMinLengthIsValid` | ✅ Pass |
| 6 | `testContentTooLongReturnsError` | ✅ Pass |
| 7 | `testContentExactlyMaxLengthIsValid` | ✅ Pass |
| 8 | `testValidStatusValide` | ✅ Pass |
| 9 | `testValidStatusBloque` | ✅ Pass |
| 10 | `testValidStatusEnAttente` | ✅ Pass |
| 11 | `testInvalidStatusReturnsFalse` | ✅ Pass |
| 12 | `testInvalidStatusCaseSensitive` | ✅ Pass |
| 13 | `testGetAllowedStatusesReturnsExpectedList` | ✅ Pass |

### 3.2 ArticleValidationServiceTest (12 tests)

**Fichier :** `tests/Service/ArticleValidationServiceTest.php`

| # | Test | Résultat |
|---|---|---|
| 1 | `testValidTitleReturnsNoErrors` | ✅ Pass |
| 2 | `testEmptyTitleReturnsError` | ✅ Pass |
| 3 | `testWhitespaceOnlyTitleReturnsError` | ✅ Pass |
| 4 | `testTitleTooShortReturnsError` | ✅ Pass |
| 5 | `testTitleExactlyMinLengthIsValid` | ✅ Pass |
| 6 | `testTitleTooLongReturnsError` | ✅ Pass |
| 7 | `testTitleExactlyMaxLengthIsValid` | ✅ Pass |
| 8 | `testValidContentReturnsNoErrors` | ✅ Pass |
| 9 | `testEmptyContentReturnsError` | ✅ Pass |
| 10 | `testWhitespaceOnlyContentReturnsError` | ✅ Pass |
| 11 | `testCanPublishWithValidArticle` | ✅ Pass |
| 12 | `testCannotPublishWithoutTitle` | ✅ Pass |
| 13 | `testCannotPublishWithEmptyContent` | ✅ Pass |
| 14 | `testCannotPublishWithTitleTooShort` | ✅ Pass |

---

## 4. Sortie terminal

### 4.1 Première exécution (filtre `--filter "Service"`)

```
$ php bin/phpunit --filter "Service" --colors=always

PHPUnit 9.6.34 by Sebastian Bergmann and contributors.

Testing
...........................                               27 / 27 (100%)

Time: 00:00.537, Memory: 10.00 MB

OK (27 tests, 40 assertions)
```

### 4.2 Exécution complète

```
$ php bin/phpunit -v --colors=never

PHPUnit 9.6.34 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.1.25
Configuration: C:\Users\Asus\Documents\pharmax\phpunit.xml.dist

...........................                               27 / 27 (100%)

Time: 00:00.018, Memory: 10.00 MB

OK (27 tests, 40 assertions)
```

### 4.3 Exécution testdox (noms lisibles)

```
$ php bin/phpunit --testdox --colors=never

Article Validation Service (App\Tests\Service\ArticleValidationService)
 ✔ Valid title returns no errors
 ✔ Empty title returns error
 ✔ Whitespace only title returns error
 ✔ Title too short returns error
 ✔ Title exactly min length is valid
 ✔ Title too long returns error
 ✔ Title exactly max length is valid
 ✔ Valid content returns no errors
 ✔ Empty content returns error
 ✔ Whitespace only content returns error
 ✔ Can publish with valid article
 ✔ Cannot publish without title
 ✔ Cannot publish with empty content
 ✔ Cannot publish with title too short

Comment Validation Service (App\Tests\Service\CommentValidationService)
 ✔ Valid content returns no errors
 ✔ Empty content returns error
 ✔ Whitespace only content returns error
 ✔ Content too short returns error
 ✔ Content exactly min length is valid
 ✔ Content too long returns error
 ✔ Content exactly max length is valid
 ✔ Valid status valide
 ✔ Valid status bloque
 ✔ Valid status en attente
 ✔ Invalid status returns false
 ✔ Invalid status case sensitive
 ✔ Get allowed statuses returns expected list

Time: 00:00.029, Memory: 10.00 MB

OK (27 tests, 40 assertions)
```

---

## 5. Refactoring effectué

### BlogController.php — Avant / Après

**Avant** (validation inline dans `addAvis()`) :
```php
if (empty(trim($contenu)) || strlen(trim($contenu)) < 2) {
    return new JsonResponse([
        'error' => 'L\'avis doit contenir au minimum 2 caractères'
    ], Response::HTTP_BAD_REQUEST);
}

if (strlen($contenu) > 1000) {
    return new JsonResponse([
        'error' => 'L\'avis ne doit pas dépasser 1000 caractères'
    ], Response::HTTP_BAD_REQUEST);
}
```

**Après** (délégation au service) :
```php
$validationErrors = $commentValidationService->validateContent($contenu);
if (!empty($validationErrors)) {
    return new JsonResponse([
        'error' => $validationErrors[0]
    ], Response::HTTP_BAD_REQUEST);
}
```

---

## 6. Commande pour rejouer les tests

```bash
php bin/phpunit
```

Pour un affichage détaillé :
```bash
php bin/phpunit --testdox
```

---

## 7. Conclusion

Tous les **27 tests unitaires** passent avec succès (**40 assertions**).  
La logique de validation a été extraite des contrôleurs vers des services dédiés, conformément aux principes de **Clean Architecture** et aux bonnes pratiques **Symfony**.

Pharmax – Application de Gestion de Pharmacie
Aperçu

Ce projet a été développé dans le cadre du module PIDEV – 3ème année cycle ingénieur à Esprit School of Engineering (année universitaire 2025–2026).

Pharmax est une application web full-stack dédiée à la gestion d’une pharmacie en ligne.
Elle permet aux clients de consulter les produits pharmaceutiques, gérer leur panier, passer des commandes avec suivi de livraison et interagir avec un blog médical.

La plateforme inclut également un tableau de bord administrateur permettant la gestion des produits, des catégories, des commandes, des réclamations et des utilisateurs.

Fonctionnalités

🛒 Catalogue de Produits – Consultation et recherche de produits pharmaceutiques par catégorie

📦 Gestion des Commandes – Panier, paiement, informations de livraison et suivi des commandes

📝 Blog & Articles – Lecture et commentaires sur des articles liés à la santé

💬 Système de Réclamations – Soumission et suivi des réclamations clients avec réponse de l’administrateur

👤 Gestion des Utilisateurs – Inscription, connexion, authentification Google OAuth et gestion du profil

🔔 Notifications – Système de notifications en temps réel pour les utilisateurs

🛡️ Détection de Fraude – Analyse automatique du risque lors des commandes

📊 Tableau de Bord Administrateur – Interface d’administration complète avec statistiques et gestion des utilisateurs

Technologies utilisées
Frontend

Twig (moteur de templates Symfony)

HTML5

CSS3

JavaScript

Template Sneat Admin Dashboard

Backend

PHP 8.x

Framework Symfony 6

Doctrine ORM 3.6

Base de données

MariaDB 10.4 / MySQL

Outils & Bibliothèques

PHPStan (analyse statique du code)

PHPUnit (tests unitaires)

Doctrine Doctor (analyse des entités et de la base de données)

Symfony Mailer (confirmation par email)

Lexik JWT (authentification API)

Architecture du Projet
pharmax/
├── src/
│   ├── Controller/        # Gestion des routes (Panier, Commande, Article, etc.)
│   ├── Entity/            # 13 entités Doctrine
│   ├── Repository/        # Requêtes base de données
│   ├── Service/           # Logique métier (fraude, facturation, etc.)
│   ├── Form/              # Formulaires Symfony
│   └── Security/          # Authentification (login, Google OAuth)
├── templates/             # Templates Twig (frontend + admin)
├── config/                # Configuration Symfony
├── migrations/            # Migrations Doctrine
├── tests/                 # Tests PHPUnit
└── public/                # Point d’entrée de l’application
Installation du Projet
Prérequis

PHP 8.x

Composer

MariaDB / MySQL

Symfony CLI (optionnel)

Installation
# Cloner le dépôt
git clone https://github.com/NayrouzDaikhi/pharmax.git
cd pharmax

# Installer les dépendances
composer install

# Configuration de l'environnement
cp .env .env.local
# Modifier .env.local avec les informations de la base de données

# Créer la base de données
php bin/console doctrine:database:create

# Exécuter les migrations
php bin/console doctrine:migrations:migrate

# Lancer le serveur de développement
symfony serve

# ou
php -S 127.0.0.1:8000 -t public
Exécution des Tests
# Analyse statique du code
php vendor/bin/phpstan analyse src --level=5

# Tests unitaires
php bin/phpunit
Remerciements

Esprit School of Engineering – Pour le cadre académique et l’accompagnement

Symfony – Framework PHP utilisé pour le développement

Doctrine ORM – Gestion de la base de données

Tous les enseignants et encadrants ayant supervisé ce projet

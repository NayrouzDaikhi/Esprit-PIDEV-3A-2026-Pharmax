# Pharmax – Pharmacy Management Application

## Overview

This project was developed as part of the **PIDEV – 3rd Year Engineering Program** at **Esprit School of Engineering** (Academic Year 2025–2026).

Pharmax is a full-stack web application for online pharmacy management. It enables customers to browse pharmaceutical products, manage their shopping cart, place orders with delivery tracking, and interact with a blog. The platform includes an admin dashboard for managing products, categories, orders, claims, and users.

## Features

- 🛒 **Product Catalog** – Browse and search pharmaceutical products by category
- 📦 **Order Management** – Shopping cart, checkout, delivery information, and order tracking
- 📝 **Blog & Articles** – Read and comment on health-related articles
- 💬 **Reclamation System** – Submit and track customer complaints with admin responses
- 👤 **User Management** – Registration, login, Google OAuth, profile management
- 🔔 **Notifications** – Real-time notification system for users
- 🛡️ **Fraud Detection** – Automated risk scoring on orders
- 📊 **Admin Dashboard** – Full back-office with order analytics and user management

## Tech Stack

### Frontend
- Twig (Symfony templating engine)
- HTML5, CSS3, JavaScript
- Sneat Admin Dashboard Template

### Backend
- PHP 8.x
- Symfony 6 Framework
- Doctrine ORM 3.6

### Database
- MariaDB 10.4 (MySQL)

### Tools & Libraries
- PHPStan (static analysis)
- PHPUnit (unit testing)
- Doctrine Doctor (database analysis)
- Mailer (email confirmations)
- Lexik JWT (API authentication)

## Architecture

```
pharmax/
├── src/
│   ├── Controller/        # Route handlers (Panier, Commande, Article, etc.)
│   ├── Entity/            # 13 Doctrine entities
│   ├── Repository/        # Database queries
│   ├── Service/           # Business logic (Fraud detection, Invoicing, etc.)
│   ├── Form/              # Symfony form types
│   └── Security/          # Authentication (Login, Google OAuth)
├── templates/             # Twig templates (frontend + admin)
├── config/                # Symfony configuration
├── migrations/            # Doctrine database migrations
├── tests/                 # PHPUnit test suites
└── public/                # Web root
```

## Contributors

| Name | Role |
|------|------|
| Nayrouz Daikhi | Developer |
| Team Members | Contributors |

## Academic Context

Developed at **Esprit School of Engineering – Tunisia**  
**PIDEV – 3A** | Academic Year **2025–2026**

This project is part of the integrated project (Projet Intégré de Développement) for 3rd-year engineering students, combining full-stack web development, database design, testing, and DevOps practices.

## Getting Started

### Prerequisites
- PHP 8.x
- Composer
- MariaDB / MySQL
- Symfony CLI (optional)

### Installation

```bash
# Clone the repository
git clone https://github.com/NayrouzDaikhi/pharmax.git
cd pharmax

# Install dependencies
composer install

# Configure environment
cp .env .env.local
# Edit .env.local with your database credentials

# Create database and run migrations
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# Start the development server
symfony serve
# or
php -S 127.0.0.1:8000 -t public
```

### Running Tests

```bash
# Static analysis
php vendor/bin/phpstan analyse src --level=5

# Unit tests
php bin/phpunit
```

## Acknowledgments

- **Esprit School of Engineering** – For providing the academic framework and guidance
- **Symfony** – The PHP framework powering this application
- **Doctrine ORM** – Object-relational mapping for database management
- All **instructors and tutors** who supervised this project

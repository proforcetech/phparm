# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHPArm is an auto repair shop management system with a custom PHP 8.1+ backend and React 19 + Vite frontend. It includes scheduling, estimates, work orders, invoicing, inventory, reporting, dispatch/roadside assistance, and an integrated CMS.

## Commands

### Development Servers
```bash
# Backend (run from project root)
php -S localhost:8000 router.php

# Frontend (separate terminal)
npm run dev
```
Frontend runs at localhost:3000 and proxies `/api` requests to the PHP backend.

### Build & Test
```bash
npm run build              # Build frontend for production
npm run test:react         # Run React tests (Vitest)
php tests/TestName.php     # Run individual PHP test
php migrate.php            # Run database migrations
```

### Code Style
```bash
composer run phpcs         # Check PHP style (PSR-12)
composer run phpcbf        # Auto-fix PHP style
```

### Docker (Optional)
```bash
docker-compose up -d       # App at :8080, MySQL at :33060, Mailhog at :8025
```

## Architecture

### Backend Structure
- **Entry point**: `public/index.php` via `router.php`
- **Routes**: `routes/api.php` (REST API), `routes/cms.php` (CMS)
- **Services**: `src/Services/` - domain-specific business logic organized by domain (Customer/, Workorder/, Invoice/, Inventory/, Dispatch/, CMS/, etc.)
- **Models**: `src/Models/` - data containers (no ORM, direct PDO queries)
- **Support**: `src/Support/` - framework utilities (Http/Router, Auth/JWT, Notifications/, Pdf/, Filesystem/, etc.)
- **Database**: `src/Database/Connection.php` - PDO singleton
- **Migrations**: `database/migrations/` - numbered SQL files run via `migrate.php`

### Frontend Structure
- **Entry**: `src/react/main.jsx` → `App.jsx`
- **Views**: `src/react/views/` - full page components organized by domain
- **Components**: `src/react/components/` - reusable UI (ui/, layout/, domain/)
- **Stores**: `src/react/stores/` - React context for auth, UI state, toasts, domain data
- **Services**: `src/react/services/` - API client, offline sync with queue support

### Key Patterns
- Custom HTTP router (not Laravel/Symfony) with middleware support
- Service-oriented backend: thin controllers, business logic in Services
- Repository pattern for data access (e.g., `CustomerRepository`, `InventoryItemRepository`)
- JWT authentication with role-based access control and optional 2FA (TOTP)
- Consistent API response format: `{ success: boolean, data: object, message: string }`
- Offline sync queue for mobile/field technician workflows

### System Roles
`admin`, `manager`, `technician`, `parts`, `dispatcher`, `roadside`, `cms`, `cms_editor`, `cms_publisher`, `customer`

Permissions defined in `config/auth.php`. Check permissions with `$user->can('permission.name')`. Admin role has 2FA required.

### Cron Jobs
The unified cron runner (`bin/cron/run.php`) should run every minute:
- Appointment reminders (hourly)
- Reminder campaigns (every 15 min)
- Low stock summaries (daily at 8 AM)

## Configuration

- **Environment**: `.env` file (see `.env.example`)
- **PHP Config**: `config/` directory (database.php, auth.php, settings.php, notifications.php, dispatch.php, cms.php, etc.)
- **Frontend**: `vite.config.js`, `tailwind.config.js`

## Key Files

- `bootstrap.php` - Application bootstrap, loads environment
- `router.php` - PHP dev server router
- `migrate.php` - Database migration runner
- `bin/cron/run.php` - Scheduled task dispatcher

## Commit Guidelines

Follow Conventional Commits: `feat:`, `fix:`, `docs:`, `style:`, `refactor:`, `perf:`, `test:`, `chore:`

Scopes: `auth`, `invoice`, `customer`, `vehicle`, `appointment`, `payment`, `inventory`, `dispatch`, `ui`, `api`, `cms`

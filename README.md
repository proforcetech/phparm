# PHPArm - Auto Repair Shop Management System

PHPArm is a full-stack auto repair shop management system built on a custom PHP 8.1 backend and a modern React + Vite frontend. It includes scheduling, estimates, work orders, invoicing, inventory, reporting, and a CMS for public-facing marketing pages.

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react)

---

## Table of Contents

- [Features](#features)
- [Technology Stack](#technology-stack)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
- [Running the Application](#running-the-application)
- [Project Structure](#project-structure)
- [CMS](#cms)
- [Development](#development)
- [Testing](#testing)
- [Deployment](#deployment)
- [Contributing](#contributing)
- [License](#license)

---

## Features

- Customer, vehicle, appointment, estimate, and work-order management
- Invoicing, payments, and credit ledger support
- Inventory catalogs and pricing
- Inspection reports and PDF generation
- Dashboard analytics and reporting
- Role-based access control
- CMS-driven marketing pages and templates
- Email notifications and reminder tracking

---

## Technology Stack

### Backend

| Technology | Version | Purpose |
|------------|---------|---------|
| PHP | 8.1+ | Server-side application |
| MySQL | 8.0+ | Primary database |
| Composer | 2.x | Dependency management |
| Dompdf | 2.x | PDF generation |

### Frontend

| Technology | Version | Purpose |
|------------|---------|---------|
| React | 19.x | UI framework |
| Vite | 5.x | Build tool & dev server |
| React Router | 6.x | Client-side routing |
| Tailwind CSS | 3.x | Utility-first styling |
| Chart.js | 4.x | Analytics charts |
| FullCalendar | 6.x | Scheduling UI |

### Architecture Notes

- The PHP backend exposes REST-style APIs under `/api`.
- The React SPA lives in `src/react` and is built with Vite.
- CMS routes are mounted alongside API routes under `/cms`.

---

## Prerequisites

- **PHP** >= 8.1 with extensions:
  - `pdo_mysql`
  - `mbstring`
  - `json`
  - `curl`
  - `gd` or `imagick`
- **MySQL** >= 8.0 (or compatible MariaDB)
- **Composer** >= 2.0
- **Node.js** >= 18 and **npm** >= 9

Optional:
- **Docker** / **Docker Compose** for containerized setup

---

## Installation

### 1. Clone the Repository

```bash
git clone https://github.com/proforcetech/phparm.git
cd phparm
```

### 2. Install Dependencies

```bash
composer install
npm install
```

### 3. Configure Environment

```bash
cp .env.example .env
```

Update the `.env` file with your local values (database credentials, JWT secret, etc.).

### 4. Create the Database

```bash
mysql -u root -p
```

```sql
CREATE DATABASE phparm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'phparm_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON phparm.* TO 'phparm_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 5. Run Migrations

```bash
php migrate.php
```

### 6. (Optional) Seed Sample Data

```bash
mysql -u phparm_user -p phparm < database/seed_data.sql
```

---

## Configuration

Key environment variables (see `.env.example` for all settings):

```env
APP_URL=http://localhost:8000
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=phparm
DB_USERNAME=phparm_user
DB_PASSWORD=your_secure_password
JWT_SECRET=generate-a-random-secret-key-here
VITE_API_URL=/api
```

Additional configuration files live under `config/` (database, auth, CMS, notifications, etc.).

---

## Running the Application

### Backend (PHP)

```bash
php -S localhost:8000 router.php
```

### Frontend (React + Vite)

```bash
npm run dev
```

- Frontend (Vite): `http://localhost:3000`
- Backend API: `http://localhost:8000/api`

The Vite dev server proxies `/api` requests to the PHP backend (see `vite.config.js`).

---

## Project Structure

```
phparm/
├── config/                 # PHP configuration
├── database/               # Schema + migrations + seed data
├── docs/                   # Supporting documentation
├── public/                 # PHP entry point + public assets
├── routes/                 # API and CMS route definitions
├── src/                    # Backend source (services, models, etc.)
│   └── react/              # React SPA source
├── storage/                # Logs and uploads
├── tests/                  # PHP test scripts
├── migrate.php             # Database migration runner
├── router.php              # PHP dev server router
├── vite.config.js          # Vite configuration
└── README.md
```

---

## CMS

PHPArm ships with an integrated CMS for managing marketing pages and templates. Routes are mounted under `/cms`.

- Admin portal: `http://localhost:8000/cms/admin`
- Public pages: `http://localhost:8000/cms/{slug}`

For more details, see [docs/CMS_INTEGRATION.md](docs/CMS_INTEGRATION.md).

---

## Development

### Frontend

- React entry point: `src/react/main.jsx`
- Routing: `src/react/router`
- Component tests: `src/react/test`

### Backend

- API routes: `routes/api.php`
- CMS routes: `routes/cms.php`
- Services: `src/Services`
- Models: `src/Models`

---

## Testing

### Backend (PHP)

Run individual test scripts in `tests/`:

```bash
php tests/InventoryItemRepositoryTest.php
php tests/VehicleMasterPolicyTest.php
```

### Frontend (React)

```bash
npm run test:react
```

---

## Deployment

### Docker (Optional)

```bash
docker-compose up -d
```

The default docker-compose setup exposes:

- App: `http://localhost:8080`
- MySQL: `localhost:33060`
- Mailhog: `http://localhost:8025`

### Cron Jobs

PHPArm includes a unified cron runner at `bin/cron/run.php`. Configure your system cron to run it every minute:

```bash
* * * * * php /path/to/phparm/bin/cron/run.php >> /var/log/phparm-cron.log 2>&1
```

Key scheduled jobs include:

- Low stock summaries: `0 8 * * *` (daily at 8 AM)
- Appointment reminders: `0 * * * *` (hourly)
- Reminder campaigns: `*/15 * * * *` (every 15 minutes)

Low stock alert recipients can be configured via the `notifications.inventory.recipient` setting (comma-separated) and will otherwise target manager-role users. Set `NOTIFICATIONS_FROM_EMAIL` as a final fallback recipient. Customize the subject via `INVENTORY_LOW_STOCK_SUBJECT`. Ensure mail settings such as `MAIL_DRIVER`, `MAIL_FROM_NAME`, and `MAIL_FROM_ADDRESS` are set for delivery.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for development standards, commit guidelines, and PR expectations.

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

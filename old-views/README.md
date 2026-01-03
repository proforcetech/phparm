# PHPArm - Auto Repair Shop Management System

A comprehensive, full-stack auto repair shop management system built with PHP backend and Vue.js frontend. PHPArm streamlines operations for auto repair shops by managing customers, vehicles, appointments, work orders, invoices, inventory, and payments.

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php)
![Vue.js](https://img.shields.io/badge/Vue.js-3.4-4FC08D?logo=vue.js)

---

## Table of Contents

- [Features](#features)
- [Technology Stack](#technology-stack)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
- [Running the Application](#running-the-application)
- [Project Structure](#project-structure)
- [API Documentation](#api-documentation)
- [Payment Gateways](#payment-gateways)
- [Development](#development)
- [Testing](#testing)
- [Deployment](#deployment)
- [Contributing](#contributing)
- [License](#license)

---

## Features

### Core Functionality

- **Customer Management**: Complete customer profiles with contact information, service history, and vehicle associations
- **Vehicle Management**: Vehicle records with VIN decoder integration (NHTSA API), service history, and maintenance tracking
- **Appointment Scheduling**: Calendar-based appointment booking with technician assignment and service type categorization
- **Work Orders**: Detailed work order management with labor tracking, parts used, and service documentation
- **Invoicing**: Professional invoice generation with line items, tax calculation, discounts, and payment tracking
- **Inventory Management**: Parts and supplies tracking with low-stock alerts and supplier management
- **Payment Processing**: Multi-gateway payment support (Stripe, Square, PayPal) with transaction history
- **PDF Generation**: Automated PDF generation for invoices and vehicle inspection reports
- **Audit Logging**: Comprehensive activity tracking for compliance and accountability
- **Role-Based Access Control**: Admin, Manager, Technician, and Receptionist roles with granular permissions

### Advanced Features

- **VIN Decoder**: Automatic vehicle information lookup using NHTSA's free API
- **Vehicle Normalization**: Data standardization for consistent reporting
- **Payment Gateway Integration**: Seamless checkout and webhook handling
- **Email Notifications**: Automated customer notifications for appointments, invoices, and work order updates
- **Dashboard Analytics**: Real-time KPIs and business metrics
- **Customer Portal**: Self-service portal for customers to view invoices, book appointments, and track vehicle service history

---

## Technology Stack

### Backend

| Technology | Version | Purpose |
|------------|---------|---------|
| PHP | 8.0+ | Server-side language |
| MySQL | 5.7+ / 8.0+ | Database |
| Composer | 2.x | Dependency management |
| DomPDF | 2.x | PDF generation |
| PHPMailer | 6.x | Email sending |
| Stripe PHP SDK | Latest | Stripe payment integration |
| Square PHP SDK | Latest | Square payment integration |
| PayPal REST SDK | Latest | PayPal payment integration |

### Frontend

| Technology | Version | Purpose |
|------------|---------|---------|
| Vue.js | 3.4+ | JavaScript framework |
| Vite | 5.0+ | Build tool & dev server |
| Vue Router | 4.2+ | Client-side routing |
| Pinia | 2.1+ | State management |
| Axios | 1.6+ | HTTP client |
| Tailwind CSS | 3.4+ | Utility-first CSS framework |
| Heroicons | 2.1+ | Icon library |

### Architecture

- **Backend**: RESTful API with PSR-4 autoloading and service-oriented architecture
- **Frontend**: Single Page Application (SPA) with component-based architecture
- **Authentication**: JWT token-based authentication with refresh tokens
- **API Proxy**: Vite dev server proxies `/api` requests to PHP backend

---

## Prerequisites

Ensure you have the following installed on your system:

### Required

- **PHP** >= 8.0 with extensions:
  - `pdo_mysql`
  - `mbstring`
  - `json`
  - `curl`
  - `gd` or `imagick` (for PDF generation)
  - `zip`
  - `xml`
- **MySQL** >= 5.7 or **MariaDB** >= 10.3
- **Composer** >= 2.0
- **Node.js** >= 18.0 and **npm** >= 9.0

### Optional

- **Redis** (for session management and caching)
- **Git** (for version control)

### Development Tools

- Code editor (VS Code, PHPStorm, etc.)
- Database management tool (phpMyAdmin, MySQL Workbench, TablePlus, etc.)

---

## Installation

### 1. Clone the Repository

```bash
git clone https://github.com/proforcetech/phparm.git
cd phparm
```

### 2. Backend Setup

#### Install PHP Dependencies

```bash
composer install
```

#### Create Database

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

#### Import Database Schema

```bash
mysql -u phparm_user -p phparm < database/schema.sql
```

If you want sample data for testing:

```bash
mysql -u phparm_user -p phparm < database/seed_data.sql
```

#### Configure Environment Variables

Create a `.env` file in the project root:

```bash
cp .env.example .env
```

Edit `.env` with your configuration:

```env
# Application
APP_NAME="PHPArm"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=phparm
DB_USERNAME=phparm_user
DB_PASSWORD=your_secure_password

# JWT Authentication
JWT_SECRET=your-random-secret-key-generate-using-openssl
JWT_EXPIRATION=3600
JWT_REFRESH_EXPIRATION=2592000

# Email
MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@phparm.local
MAIL_FROM_NAME="PHPArm"

# Payment Gateways (Optional - see Payment Gateways section)
STRIPE_SECRET_KEY=
STRIPE_PUBLISHABLE_KEY=
STRIPE_WEBHOOK_SECRET=

SQUARE_ACCESS_TOKEN=
SQUARE_LOCATION_ID=
SQUARE_ENVIRONMENT=sandbox
SQUARE_WEBHOOK_SECRET=

PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_MODE=sandbox
PAYPAL_WEBHOOK_ID=

# File Storage
UPLOAD_MAX_SIZE=10485760
STORAGE_PATH=storage

# API
API_RATE_LIMIT=60
API_RATE_LIMIT_WINDOW=60
```

#### Generate JWT Secret

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Copy the output and paste it as `JWT_SECRET` in your `.env` file.

#### Set Permissions

```bash
chmod -R 775 storage
chmod -R 775 bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### 3. Frontend Setup

#### Install Node Dependencies

```bash
npm install
```

#### Configure Frontend Environment

Create `src/config/env.js` (optional, defaults work for local development):

```javascript
export default {
  API_BASE_URL: import.meta.env.VITE_API_URL || '/api',
  APP_NAME: import.meta.env.VITE_APP_NAME || 'PHPArm',
}
```

---

## Configuration

### Database Configuration

Edit `config/database.php` if you need custom database settings beyond the `.env` file.

### Payment Gateway Setup

See [docs/PAYMENT_GATEWAYS.md](docs/PAYMENT_GATEWAYS.md) for detailed setup instructions for:

- Stripe
- Square
- PayPal

Each gateway requires:
1. Creating an account with the provider
2. Obtaining API credentials
3. Configuring webhook endpoints
4. Testing with sandbox/test mode

### reCAPTCHA Configuration

Public authentication forms use Google reCAPTCHA for abuse prevention. Configure the following variables in your `.env` files:

```bash
RECAPTCHA_SITE_KEY="your_public_site_key"
RECAPTCHA_SECRET_KEY="your_private_secret"
RECAPTCHA_THRESHOLD=0.5
VITE_RECAPTCHA_SITE_KEY="your_public_site_key" # exposed to the frontend
```

- `RECAPTCHA_SITE_KEY` / `VITE_RECAPTCHA_SITE_KEY`: the site key from the reCAPTCHA admin console (the Vite-prefixed copy is required for the Vue app to render the widget).
- `RECAPTCHA_SECRET_KEY`: secret key used for server-side verification.
- `RECAPTCHA_THRESHOLD`: minimum acceptable score (0–1) for v3/invisible challenges; defaults to `0.5`.

### Email Configuration

For development, use [Mailtrap.io](https://mailtrap.io) for email testing:

1. Create a free Mailtrap account
2. Get your SMTP credentials
3. Update `.env` with Mailtrap settings

For production, use:
- **SendGrid** (recommended)
- **Amazon SES**
- **Mailgun**
- **Postmark**

### File Upload Configuration

Configure upload limits in `php.ini`:

```ini
upload_max_filesize = 10M
post_max_size = 10M
memory_limit = 128M
```

And in `.htaccess`:

```apache
php_value upload_max_filesize 10M
php_value post_max_size 10M
```

---

## Running the Application

### Development Mode

#### Start PHP Backend

Using PHP's built-in server:

```bash
php -S localhost:8000 -t public
```

Or using Apache/Nginx (recommended for production-like testing):

**Apache** - Ensure `mod_rewrite` is enabled and `.htaccess` is configured.

**Nginx** - Use the provided `nginx.conf` configuration:

```bash
cp deployment/nginx.conf /etc/nginx/sites-available/phparm
sudo nginx -s reload
```

#### Start Frontend Development Server

In a new terminal:

```bash
npm run dev
```

The frontend will be available at: `http://localhost:3000`

API requests to `/api/*` are automatically proxied to `http://localhost:8000`

### Production Mode

#### Build Frontend

```bash
npm run build
```

This creates optimized static files in the `dist/` directory.

#### Configure Web Server

Serve the built frontend and configure the backend API endpoint. See [Deployment](#deployment) for detailed instructions.

### Accessing the Application

- **Frontend**: http://localhost:3000
- **Backend API**: http://localhost:8000/api
- **API Documentation**: http://localhost:8000/api/docs (if enabled)

### Default Admin Credentials

After running the database seed:

```
Email: admin@phparm.local
Password: admin123
```

**⚠️ IMPORTANT**: Change the admin password immediately after first login!

---

## Project Structure

```
phparm/
├── config/                 # Configuration files
│   ├── app.php            # Application settings
│   ├── database.php       # Database configuration
│   └── payments.php       # Payment gateway configuration
│
├── database/              # Database files
│   ├── schema.sql         # Database schema
│   └── seed_data.sql      # Sample data
│
├── docs/                  # Documentation
│   ├── API.md            # API documentation
│   ├── FRONTEND_ARCHITECTURE.md
│   └── PAYMENT_GATEWAYS.md
│
├── public/                # Public web root
│   ├── index.php         # Application entry point
│   └── .htaccess         # Apache rewrite rules
│
├── routes/                # API routes
│   └── api.php           # API endpoint definitions
│
├── src/                   # Application source code
│   ├── Controllers/      # API controllers
│   │   ├── AppointmentController.php
│   │   ├── CustomerController.php
│   │   ├── InvoiceController.php
│   │   └── ...
│   │
│   ├── Services/         # Business logic services
│   │   ├── Auth/         # Authentication services
│   │   ├── Customer/     # Customer management
│   │   ├── Invoice/      # Invoice & payment services
│   │   ├── Payment/      # Payment gateway integrations
│   │   ├── PDF/          # PDF generation
│   │   ├── Vehicle/      # Vehicle & VIN services
│   │   └── ...
│   │
│   ├── Models/           # Database models
│   │   ├── User.php
│   │   ├── Customer.php
│   │   └── ...
│   │
│   ├── Middleware/       # HTTP middleware
│   │   ├── Auth.php
│   │   ├── RoleCheck.php
│   │   └── ...
│   │
│   ├── Utils/            # Utility classes
│   │   ├── Database.php
│   │   ├── Validator.php
│   │   └── ...
│   │
│   ├── assets/           # Frontend assets
│   │   └── styles/       # Global styles
│   │
│   ├── components/       # Vue components
│   │   ├── layout/       # Layout components
│   │   └── ui/           # Reusable UI components
│   │
│   ├── router/           # Vue Router configuration
│   │   └── index.js
│   │
│   ├── services/         # Frontend API services
│   │   ├── api.js
│   │   ├── auth.service.js
│   │   └── ...
│   │
│   ├── stores/           # Pinia state stores
│   │   └── auth.js
│   │
│   ├── views/            # Vue page components
│   │   ├── auth/         # Authentication pages
│   │   ├── invoices/     # Invoice pages
│   │   └── ...
│   │
│   ├── App.vue           # Root Vue component
│   └── main.js           # Frontend entry point
│
├── storage/               # Storage directory
│   ├── logs/             # Application logs
│   ├── uploads/          # Uploaded files
│   └── temp/             # Temporary files
│
├── tests/                 # Test files
│   ├── test-vin-decoder.php
│   └── ...
│
├── .env.example          # Environment template
├── .gitignore
├── composer.json         # PHP dependencies
├── package.json          # Node dependencies
├── vite.config.js        # Vite configuration
├── tailwind.config.js    # Tailwind CSS configuration
└── README.md             # This file
```

---

## API Documentation

### Authentication

All API requests (except public endpoints) require JWT authentication.

#### Login

```http
POST /api/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password123"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "user": {
      "id": 1,
      "email": "user@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "role": "admin"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "expires_in": 3600
  }
}
```

#### Using the Token

Include the token in the Authorization header:

```http
GET /api/customers
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

### API Endpoints Overview

| Resource | Endpoints |
|----------|-----------|
| **Authentication** | `POST /api/auth/login`, `POST /api/auth/logout`, `POST /api/auth/register`, `POST /api/auth/refresh` |
| **Customers** | `GET/POST /api/customers`, `GET/PUT/DELETE /api/customers/{id}` |
| **Vehicles** | `GET/POST /api/vehicles`, `GET/PUT/DELETE /api/vehicles/{id}`, `POST /api/vehicles/decode-vin` |
| **Appointments** | `GET/POST /api/appointments`, `GET/PUT/DELETE /api/appointments/{id}` |
| **Work Orders** | `GET/POST /api/work-orders`, `GET/PUT/DELETE /api/work-orders/{id}` |
| **Invoices** | `GET/POST /api/invoices`, `GET/PUT/DELETE /api/invoices/{id}`, `POST /api/invoices/{id}/send`, `GET /api/invoices/{id}/pdf` |
| **Payments** | `POST /api/invoices/{id}/checkout`, `POST /api/webhooks/payments/{provider}` |
| **Inventory** | `GET/POST /api/inventory`, `GET/PUT/DELETE /api/inventory/{id}` |
| **Dashboard** | `GET /api/dashboard/stats`, `GET /api/dashboard/revenue-chart` |

For complete API documentation, see [docs/API.md](docs/API.md).

---

## Payment Gateways

PHPArm supports three major payment gateways:

### Stripe

1. **Sign up**: [https://stripe.com](https://stripe.com)
2. **Get API keys**: Dashboard → Developers → API Keys
3. **Install SDK**: `composer require stripe/stripe-php`
4. **Configure webhooks**: Add endpoint `https://yourdomain.com/api/webhooks/payments/stripe`
5. **Update .env**:
   ```env
   STRIPE_SECRET_KEY=sk_test_...
   STRIPE_PUBLISHABLE_KEY=pk_test_...
   STRIPE_WEBHOOK_SECRET=whsec_...
   ```

### Square

1. **Sign up**: [https://squareup.com](https://squareup.com)
2. **Get access token**: Dashboard → Apps → OAuth
3. **Install SDK**: `composer require square/square`
4. **Configure webhooks**: Developer Dashboard → Webhooks
5. **Update .env**:
   ```env
   SQUARE_ACCESS_TOKEN=EAAAl...
   SQUARE_LOCATION_ID=LRK...
   SQUARE_ENVIRONMENT=sandbox
   SQUARE_WEBHOOK_SECRET=your-signature-key
   ```

### PayPal

1. **Sign up**: [https://developer.paypal.com](https://developer.paypal.com)
2. **Create app**: Dashboard → My Apps & Credentials
3. **Install SDK**: `composer require paypal/rest-api-sdk-php`
4. **Configure webhooks**: Webhooks → Add Webhook
5. **Update .env**:
   ```env
   PAYPAL_CLIENT_ID=...
   PAYPAL_CLIENT_SECRET=...
   PAYPAL_MODE=sandbox
   PAYPAL_WEBHOOK_ID=...
   ```

For detailed setup instructions, see [docs/PAYMENT_GATEWAYS.md](docs/PAYMENT_GATEWAYS.md).

---

## Development

### Code Style

**PHP**: Follow PSR-12 coding standards

```bash
composer run phpcs  # Check code style
composer run phpcbf # Fix code style automatically
```

**JavaScript/Vue**: Use ESLint with Vue plugin

```bash
npm run lint       # Check code style
npm run lint:fix   # Fix code style automatically
```

### Database Migrations

When making schema changes, update `database/schema.sql` and document changes in `CHANGELOG.md`.

### Creating New Components

#### Backend Controller

```php
<?php
namespace App\Controllers;

use App\Models\User;

class ExampleController
{
    public function index(User $user, array $data)
    {
        // Controller logic
        return [
            'success' => true,
            'data' => []
        ];
    }
}
```

#### Frontend Vue Component

```vue
<template>
  <div>
    <!-- Template -->
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

// Component logic
</script>
```

### Adding New Routes

**Backend** (`routes/api.php`):

```php
$router->get('/api/example', [$exampleController, 'index'], ['auth']);
```

**Frontend** (`src/router/index.js`):

```javascript
{
  path: '/example',
  component: () => import('@/views/Example.vue'),
  meta: { requiresAuth: true }
}
```

---

## Testing

### Backend Tests

Run PHP unit tests:

```bash
composer test
```

Test specific functionality:

```bash
php tests/test-vin-decoder.php
```

### Frontend Tests

Run Vue component tests:

```bash
npm run test
```

Run E2E tests:

```bash
npm run test:e2e
```

### Manual Testing

Use the following test data:

**Test Credit Cards** (Stripe):
- Success: `4242 4242 4242 4242`
- Decline: `4000 0000 0000 0002`

**Test VINs**:
- Valid: `1HGBH41JXMN109186`
- Valid: `5YJSA1E14HF000001`

---

## Deployment

### Preparation

1. **Update environment**:
   ```bash
   cp .env .env.production
   ```
   Edit `.env.production` with production values:
   - Set `APP_ENV=production`
   - Set `APP_DEBUG=false`
   - Use production database credentials
   - Use production payment gateway keys
   - Use production email settings

2. **Build frontend**:
   ```bash
   npm run build
   ```

3. **Optimize backend**:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

### Deployment Options

#### Option 1: Traditional Web Server

**Apache**:

1. Upload files to web server
2. Point document root to `public/`
3. Ensure `.htaccess` is active
4. Set proper file permissions
5. Configure virtual host

**Nginx**:

1. Upload files to server
2. Use provided `deployment/nginx.conf`
3. Configure PHP-FPM
4. Set proper file permissions
5. Restart Nginx

#### Option 2: Docker

```bash
docker-compose up -d
```

See `docker-compose.yml` for configuration.

#### Option 3: Cloud Platforms

- **AWS**: EC2 + RDS + S3
- **DigitalOcean**: Droplet + Managed Database
- **Heroku**: Buildpack deployment
- **Laravel Forge**: Automated deployment (PHP compatible)

### Post-Deployment

1. **Run database migrations** (if any)
2. **Clear caches**:
   ```bash
   php artisan cache:clear
   php artisan config:cache
   ```
3. **Test payment webhooks** using provider test mode
4. **Monitor logs**: `storage/logs/`
5. **Set up SSL certificate** (Let's Encrypt recommended)
6. **Configure backups** for database and uploads
7. **Set up monitoring** (New Relic, Sentry, etc.)

### Security Checklist

- [ ] Change default admin password
- [ ] Set `APP_DEBUG=false` in production
- [ ] Use strong `JWT_SECRET`
- [ ] Enable HTTPS/SSL
- [ ] Configure CORS properly
- [ ] Set restrictive file permissions
- [ ] Enable rate limiting
- [ ] Regular security updates
- [ ] Database backups automated
- [ ] Use environment variables for secrets
- [ ] Enable firewall (UFW, iptables)

---

## Contributing

We welcome contributions! Please follow these guidelines:

### Getting Started

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Make your changes
4. Run tests: `composer test && npm test`
5. Commit your changes: `git commit -m 'Add amazing feature'`
6. Push to branch: `git push origin feature/amazing-feature`
7. Open a Pull Request

### Commit Message Convention

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add customer portal dashboard
fix: resolve invoice PDF generation issue
docs: update API documentation
refactor: optimize database queries
test: add VIN decoder tests
```

### Code Review Process

1. All PRs require at least one review
2. All tests must pass
3. Code must follow style guidelines
4. Documentation must be updated

### Reporting Issues

Use GitHub Issues with the following information:

- Clear description of the issue
- Steps to reproduce
- Expected vs actual behavior
- Screenshots (if applicable)
- Environment details (PHP version, browser, etc.)

---

## Troubleshooting

### Common Issues

#### "Class not found" error

```bash
composer dump-autoload
```

#### Database connection failed

- Check `.env` database credentials
- Verify MySQL service is running
- Test connection: `mysql -u username -p database_name`

#### VIN decoder not working

- Check internet connectivity (requires external API)
- Verify NHTSA API is accessible
- Review logs in `storage/logs/`

#### Payment webhook not received

- Verify webhook URL is accessible from internet
- Check firewall settings
- Test with provider's webhook tester
- Review webhook signature validation

#### Frontend can't connect to API

- Verify PHP backend is running
- Check Vite proxy configuration in `vite.config.js`
- Review browser console for CORS errors
- Verify API routes in `routes/api.php`

### Getting Help

- **Documentation**: Check `docs/` directory
- **Issues**: [GitHub Issues](https://github.com/proforcetech/phparm/issues)
- **Discussions**: [GitHub Discussions](https://github.com/proforcetech/phparm/discussions)

---

## Roadmap

### Version 2.0 (Planned)

- [ ] Multi-shop support
- [ ] Advanced reporting and analytics
- [ ] Mobile app (iOS/Android)
- [ ] Technician mobile app
- [ ] SMS notifications (Twilio integration)
- [ ] Parts ordering integration (AutoZone, O'Reilly APIs)
- [ ] Warranty tracking
- [ ] Fleet management features
- [ ] AI-powered diagnostic suggestions
- [ ] Multi-language support
- [ ] Dark mode UI

### Version 1.5 (In Progress)

- [x] Payment gateway integration
- [x] VIN decoder
- [x] Customer portal
- [ ] Email templates customization
- [ ] Advanced appointment scheduling
- [ ] Service history reports
- [ ] Customer loyalty program

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

### Third-Party Licenses

This project uses several open-source libraries. See [LICENSES.md](LICENSES.md) for details.

---

## Acknowledgments

- **NHTSA** for the free VIN decoder API
- **Stripe**, **Square**, and **PayPal** for payment processing
- **Vue.js** and **Tailwind CSS** communities for excellent documentation
- All contributors who help improve this project

---

## Support

If you find this project helpful, please consider:

- ⭐ Starring the repository
- 🐛 Reporting bugs
- 💡 Suggesting new features
- 📖 Improving documentation
- 🔀 Contributing code

---

## Contact

- **Project Repository**: [https://github.com/proforcetech/phparm](https://github.com/proforcetech/phparm)
- **Issue Tracker**: [https://github.com/proforcetech/phparm/issues](https://github.com/proforcetech/phparm/issues)
- **Website**: [https://phparm.dev](https://phparm.dev)
- **Email**: support@phparm.dev

---

**Built with ❤️ by the PHPArm Team**

*Last updated: December 2025*

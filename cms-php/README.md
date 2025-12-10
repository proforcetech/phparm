# FixItForUs CMS

A lightweight PHP-based Content Management System with MySQL database storage and built-in caching.

## Features

- **Page Management**: Create, edit, and publish pages with custom content
- **Reusable Components**: Header, footer, navigation, and custom widgets stored in database
- **Template System**: Define page layouts with placeholder support
- **Dual-Layer Caching**: File-based and database caching for optimal performance
- **Admin Dashboard**: Modern dark-themed admin interface
- **User Management**: Role-based access control (Admin, Editor, Viewer)
- **SEO Support**: Meta descriptions, keywords, and XML sitemap generation

## Requirements

- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.2+
- Apache with mod_rewrite or Nginx

## Installation

### 1. Database Setup

Create the database and tables:

```bash
mysql -u root -p < cms-php/schema.sql
```

Optionally, load sample data (header, footer, homepage):

```bash
mysql -u root -p fixitforus_cms < cms-php/sample-data.sql
```

### 2. Configuration

Copy the environment file and configure:

```bash
cp cms-php/.env.example cms-php/.env
```

Edit `.env` with your database credentials:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=fixitforus_cms
DB_USER=your_username
DB_PASSWORD=your_password

APP_URL=https://your-domain.com
APP_DEBUG=false
APP_SECRET=your-random-32-char-secret
```

### 3. Web Server Configuration

#### Apache
The included `.htaccess` file handles URL rewriting. Ensure `mod_rewrite` is enabled.

#### Nginx
Add to your server configuration:

```nginx
location /cms-php/ {
    try_files $uri $uri/ /cms-php/index.php?$query_string;
}

location /cms-php/admin {
    try_files $uri $uri/ /cms-php/admin.php?$query_string;
}
```

## Usage

### Admin Panel

Access the admin panel at: `https://your-domain.com/cms-php/admin.php`

Default credentials:
- Username: `admin`
- Password: `admin123`

**Important**: Change the default password immediately after first login!

### Creating Pages

1. Go to **Pages** in the admin panel
2. Click **New Page**
3. Fill in the title, content, and SEO fields
4. Select a template and header/footer components
5. Toggle **Published** and save

### Using Components in Pages

Embed reusable components in page content using:

```html
{{component:component-slug}}
```

Example:
```html
<div class="content">
    <h1>Welcome to our site</h1>
    <p>Some content here...</p>

    {{component:cta-banner}}
</div>
```

### Creating Templates

Templates define page structure using placeholders:

```html
<!DOCTYPE html>
<html>
<head>
    <title>{{title}}</title>
    <meta name="description" content="{{meta_description}}">
    <style>{{default_css}}{{custom_css}}</style>
</head>
<body>
    {{header}}
    <main>{{breadcrumbs}}{{content}}</main>
    {{footer}}
    <script>{{default_js}}{{custom_js}}</script>
</body>
</html>
```

Available placeholders:
- `{{title}}` - Page title
- `{{meta_description}}` - SEO description
- `{{meta_keywords}}` - SEO keywords
- `{{header}}` - Header component
- `{{footer}}` - Footer component
- `{{content}}` - Page content
- `{{breadcrumbs}}` - Breadcrumb navigation
- `{{default_css}}` - Template CSS
- `{{custom_css}}` - Page-specific CSS
- `{{default_js}}` - Template JavaScript
- `{{custom_js}}` - Page-specific JavaScript

## Caching

The CMS implements two-tier caching:

1. **File Cache**: Fast, file-based caching for rendered pages
2. **Database Cache**: Stores cached content with TTL

Cache is automatically invalidated when content is updated in the admin panel.

### Manual Cache Management

Clear cache via admin panel: **System > Cache**

Or programmatically:

```php
use CMS\Models\Cache;

$cache = new Cache();
$cache->clearAll();           // Clear all cache
$cache->clearByType('page');  // Clear page cache only
$cache->cleanExpired();       // Remove expired entries
```

## Directory Structure

```
cms-php/
├── admin.php           # Admin entry point
├── index.php           # Front-end entry point
├── schema.sql          # Database schema
├── sample-data.sql     # Sample content
├── .env.example        # Environment template
├── .htaccess           # Apache rewrite rules
├── config/
│   ├── bootstrap.php   # Application bootstrap
│   └── database.php    # Database connection
├── models/
│   ├── Page.php        # Page model
│   ├── Component.php   # Component model
│   ├── Template.php    # Template model
│   ├── Cache.php       # Cache model
│   ├── User.php        # User model
│   └── Setting.php     # Settings model
├── controllers/
│   ├── AdminController.php  # Admin routes
│   └── PageController.php   # Front-end rendering
├── views/
│   ├── layouts/        # Layout templates
│   └── admin/          # Admin view files
├── assets/
│   ├── css/            # Stylesheets
│   └── js/             # JavaScript
└── cache/              # File cache directory
```

## Security

- CSRF protection on all forms
- Password hashing with bcrypt
- SQL injection prevention via prepared statements
- XSS protection with output escaping
- Session security measures

## License

Proprietary - FixItForUs

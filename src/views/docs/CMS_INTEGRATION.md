# CMS Integration Guide

The FixItForUs CMS has been integrated into the PHPArm application to manage the front-end website.

## Overview

The CMS integration allows you to:
- Create and manage web pages with a WYSIWYG interface
- Define reusable components (headers, footers, widgets)
- Create custom templates
- Manage users with role-based access control
- Cache content for optimal performance

## Architecture

The CMS has been integrated into the main application using the following approach:

1. **Namespace Integration**: CMS classes use the `CMS\` namespace and are autoloaded via Composer
2. **Route Integration**: CMS routes are registered in `/routes/cms.php` and loaded in `public/index.php`
3. **Configuration**: CMS settings are defined in `/config/cms.php` and loaded in `bootstrap.php`
4. **Initialization**: The `CMSBootstrap` class initializes the CMS environment within the main application

## File Structure

```
phparm/
├── cms-php/                    # Original CMS directory
│   ├── admin.php              # (Not used - routes handled by main app)
│   ├── index.php              # (Not used - routes handled by main app)
│   ├── models/                # CMS models (Page, Component, Template, etc.)
│   ├── controllers/           # CMS controllers (AdminController, PageController)
│   ├── views/                 # CMS view templates
│   ├── assets/                # CMS static assets (CSS, JS, images)
│   ├── cache/                 # File-based cache storage
│   ├── config/                # CMS-specific configuration
│   └── schema.sql             # Database schema
├── config/
│   └── cms.php                # CMS configuration for main app
├── routes/
│   └── cms.php                # CMS routes (admin and public)
└── src/
    └── CMS/
        └── CMSBootstrap.php   # CMS initialization class
```

## Database Setup

### 1. Create the CMS Database Tables

Run the schema to create the required tables:

```bash
mysql -u root -p phparm < cms-php/schema.sql
```

This creates the following tables:
- `cms_pages` - Website pages
- `cms_components` - Reusable content components
- `cms_templates` - Page templates
- `cms_cache` - Database cache entries
- `cms_users` - CMS user accounts
- `cms_settings` - System settings

### 2. (Optional) Load Sample Data

To get started quickly with sample content:

```bash
mysql -u root -p phparm < cms-php/sample-data.sql
```

This creates:
- A default homepage
- Header and footer components
- A default template
- An admin user account (username: `admin`, password: `admin123`)

**Important**: Change the default admin password immediately after first login!

## URL Structure

### Public CMS Routes

- `http://localhost:8000/cms` - Homepage
- `http://localhost:8000/cms/{slug}` - Dynamic pages by slug
- `http://localhost:8000/cms/sitemap.xml` - XML sitemap

### Admin CMS Routes

- `http://localhost:8000/cms/admin` - Admin dashboard
- `http://localhost:8000/cms/admin/login` - Login page
- `http://localhost:8000/cms/admin/pages` - Manage pages
- `http://localhost:8000/cms/admin/components` - Manage components
- `http://localhost:8000/cms/admin/templates` - Manage templates
- `http://localhost:8000/cms/admin/users` - Manage users
- `http://localhost:8000/cms/admin/settings` - System settings
- `http://localhost:8000/cms/admin/cache` - Cache management

## Configuration

The CMS is configured in `/config/cms.php`:

```php
return [
    'routes' => [
        'admin_prefix' => '/cms/admin',    // Admin panel URL prefix
        'public_prefix' => '/cms',         // Public pages URL prefix
    ],
    'cache' => [
        'enabled' => env('CMS_CACHE_ENABLED', true),
        'ttl' => env('CMS_CACHE_TTL', 3600),
        'driver' => 'file', // file or database
    ],
    // ... other settings
];
```

### Environment Variables

Add these to your `.env` file:

```env
# CMS Configuration
CMS_CACHE_ENABLED=true
CMS_CACHE_TTL=3600
CMS_CACHE_DRIVER=file # file or redis
CMS_CACHE_PATH=/path/to/project/storage/cms-cache
CMS_CACHE_REDIS_HOST=127.0.0.1
CMS_CACHE_REDIS_PORT=6379
CMS_CACHE_REDIS_PASSWORD=
CMS_CACHE_REDIS_DB=0
CMS_CACHE_PREFIX=cms:
```

The CMS uses the same database connection as the main application (configured via `DB_HOST`, `DB_NAME`, etc.).

## Usage

### Creating a New Page

1. Go to `http://localhost:8000/cms/admin/pages`
2. Click "New Page"
3. Fill in:
   - **Title**: Page title
   - **Slug**: URL-friendly identifier (e.g., `about-us`)
   - **Content**: Page HTML content
   - **Meta Description**: SEO description
   - **Meta Keywords**: SEO keywords
4. Select a template and header/footer components
5. Toggle "Published" to make it live
6. Click "Save"

The page will be accessible at: `http://localhost:8000/cms/about-us`

### Creating Reusable Components

Components are reusable pieces of content (like headers, footers, call-to-action banners, etc.).

1. Go to `http://localhost:8000/cms/admin/components`
2. Click "New Component"
3. Fill in:
   - **Name**: Component name
   - **Slug**: Identifier (e.g., `header`)
   - **Type**: Component type (header, footer, widget, etc.)
   - **Content**: HTML content
4. Click "Save"

To use a component in a page, add:

```html
{{component:header}}
```

### Creating Templates

Templates define the overall page structure.

1. Go to `http://localhost:8000/cms/admin/templates`
2. Click "New Template"
3. Define the HTML structure using placeholders:

```html
<!DOCTYPE html>
<html>
<head>
    <title>{{title}}</title>
    <meta name="description" content="{{meta_description}}">
    <style>{{default_css}}</style>
</head>
<body>
    {{header}}
    <main>
        {{content}}
    </main>
    {{footer}}
    <script>{{default_js}}</script>
</body>
</html>
```

Available placeholders:
- `{{title}}` - Page title
- `{{content}}` - Page content
- `{{header}}` - Header component
- `{{footer}}` - Footer component
- `{{meta_description}}` - SEO description
- `{{meta_keywords}}` - SEO keywords
- `{{default_css}}` - Template CSS
- `{{custom_css}}` - Page-specific CSS
- `{{default_js}}` - Template JavaScript
- `{{custom_js}}` - Page-specific JavaScript
- `{{breadcrumbs}}` - Breadcrumb navigation

## Cache Management

The CMS implements two-tier caching:

1. **File Cache**: Fast, file-based caching in `cms-php/cache/`
2. **Database Cache**: Stored in the `cms_cache` table

Cache is automatically cleared when content is updated. To manually clear cache:

1. Go to `http://localhost:8000/cms/admin/cache`
2. Click "Clear All Cache"

Or programmatically:

```php
use CMS\Models\Cache;

$cache = new Cache();
$cache->clearAll();           // Clear all cache
$cache->clearByType('page');  // Clear page cache only
```

## Security

The CMS includes:

- **CSRF Protection**: All forms include CSRF tokens
- **Password Hashing**: Passwords are hashed using bcrypt
- **SQL Injection Prevention**: All queries use prepared statements
- **XSS Protection**: Output is escaped
- **Session Security**: Session name isolation from main app

The CMS uses separate session keys (prefixed with `cms_`) to avoid conflicts with the main application's authentication system.

## Helper Functions

The integration provides helper functions:

```php
cms_url('/about');              // Generate CMS URL
cms_admin_url('/pages');        // Generate admin URL
cms_is_logged_in();             // Check if CMS user is logged in
cms_current_user_id();          // Get current CMS user ID
cms_flash('success', 'Saved!'); // Set flash message
cms_get_flash('success');       // Get flash message
cms_csrf_token();               // Get CSRF token
cms_csrf_field();               // Generate CSRF input field
e($string);                     // Escape HTML
```

## Troubleshooting

### Routes Not Working

1. Ensure `composer dump-autoload` has been run
2. Verify the CMS routes are loaded in `public/index.php`
3. Check that the database connection is configured correctly

### Cache Issues

1. Ensure `cms-php/cache/` directory is writable:
   ```bash
   chmod -R 775 cms-php/cache/
   ```

2. Clear cache manually:
   ```bash
   rm -rf cms-php/cache/*
   ```

### Database Connection Errors

1. Verify database credentials in `.env`
2. Ensure the CMS tables have been created using `schema.sql`
3. Check that the database user has proper permissions

## Integration with Main Application

The CMS is designed to be independent from the main PHPArm application:

- Uses separate database tables (all prefixed with `cms_`)
- Uses separate session keys (all prefixed with `cms_`)
- Can be accessed alongside the main application's API routes

To link to CMS pages from the main application:

```php
$pageUrl = cms_url('/about-us');
echo "<a href='{$pageUrl}'>About Us</a>";
```

## Next Steps

1. Change the default admin password
2. Create your homepage content
3. Define your site's header and footer components
4. Create your custom templates
5. Add your website pages
6. Customize the styling in templates and components

For detailed CMS documentation, see `cms-php/README.md`.

<?php

namespace App\Database\Seeders;

use App\Database\Connection;
use DateTimeImmutable;
use PDO;

/**
 * CMS Seeder
 *
 * Seeds the CMS tables with default data including:
 * - Default admin user
 * - Default settings
 * - Default template
 * - Sample header and footer components
 * - Sample home page
 */
class CMSSeeder
{
    private Connection $connection;
    private string $tablePrefix;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->tablePrefix = env('CMS_TABLE_PREFIX', '');
    }

    /**
     * Get table name with prefix
     */
    private function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }

    /**
     * Run all CMS seeders
     */
    public function seed(): void
    {
        $this->seedUsers();
        $this->seedSettings();
        $this->seedTemplates();
        $this->seedComponents();
        $this->seedSamplePages();
    }

    /**
     * Seed default CMS admin user
     * This user is for standalone CMS access (backup/legacy mode)
     */
    private function seedUsers(): void
    {
        $pdo = $this->connection->pdo();

        // Password: admin123 (for development only - change in production)
        $passwordHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

        $stmt = $pdo->prepare("
            INSERT INTO {$this->table('users')}
            (username, email, password_hash, role, is_active, created_at, updated_at)
            VALUES (:username, :email, :password_hash, :role, :is_active, :now, :now)
            ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                role = VALUES(role),
                is_active = VALUES(is_active),
                updated_at = VALUES(updated_at)
        ");

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt->execute([
            'username' => 'admin',
            'email' => 'admin@fixitforus.com',
            'password_hash' => $passwordHash,
            'role' => 'admin',
            'is_active' => 1,
            'now' => $now,
        ]);
    }

    /**
     * Seed CMS settings
     */
    private function seedSettings(): void
    {
        $pdo = $this->connection->pdo();

        $settings = [
            [
                'setting_key' => 'site_name',
                'setting_value' => 'FixItForUs',
                'setting_type' => 'string',
                'description' => 'Site name displayed in header and title',
                'is_public' => 1,
            ],
            [
                'setting_key' => 'site_tagline',
                'setting_value' => 'Mobile Auto Repair Services',
                'setting_type' => 'string',
                'description' => 'Site tagline/description',
                'is_public' => 1,
            ],
            [
                'setting_key' => 'default_header_component',
                'setting_value' => 'main-header',
                'setting_type' => 'string',
                'description' => 'Default header component slug',
                'is_public' => 0,
            ],
            [
                'setting_key' => 'default_footer_component',
                'setting_value' => 'main-footer',
                'setting_type' => 'string',
                'description' => 'Default footer component slug',
                'is_public' => 0,
            ],
            [
                'setting_key' => 'default_template',
                'setting_value' => 'default',
                'setting_type' => 'string',
                'description' => 'Default page template slug',
                'is_public' => 0,
            ],
            [
                'setting_key' => 'cache_enabled',
                'setting_value' => '1',
                'setting_type' => 'boolean',
                'description' => 'Enable/disable caching',
                'is_public' => 0,
            ],
            [
                'setting_key' => 'cache_ttl',
                'setting_value' => '3600',
                'setting_type' => 'integer',
                'description' => 'Default cache TTL in seconds',
                'is_public' => 0,
            ],
            [
                'setting_key' => 'maintenance_mode',
                'setting_value' => '0',
                'setting_type' => 'boolean',
                'description' => 'Enable maintenance mode',
                'is_public' => 0,
            ],
            [
                'setting_key' => 'google_analytics_id',
                'setting_value' => '',
                'setting_type' => 'string',
                'description' => 'Google Analytics tracking ID',
                'is_public' => 0,
            ],
            [
                'setting_key' => 'contact_phone',
                'setting_value' => '(616) 200-7121',
                'setting_type' => 'string',
                'description' => 'Contact phone number',
                'is_public' => 1,
            ],
            [
                'setting_key' => 'contact_email',
                'setting_value' => 'info@fixitforus.com',
                'setting_type' => 'string',
                'description' => 'Contact email address',
                'is_public' => 1,
            ],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO {$this->table('settings')}
            (setting_key, setting_value, setting_type, description, is_public, created_at, updated_at)
            VALUES (:setting_key, :setting_value, :setting_type, :description, :is_public, :now, :now)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                setting_type = VALUES(setting_type),
                description = VALUES(description),
                is_public = VALUES(is_public),
                updated_at = VALUES(updated_at)
        ");

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($settings as $setting) {
            $stmt->execute([
                'setting_key' => $setting['setting_key'],
                'setting_value' => $setting['setting_value'],
                'setting_type' => $setting['setting_type'],
                'description' => $setting['description'],
                'is_public' => $setting['is_public'],
                'now' => $now,
            ]);
        }
    }

    /**
     * Seed default templates
     */
    private function seedTemplates(): void
    {
        $pdo = $this->connection->pdo();

        $defaultTemplate = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{meta_description}}">
    <meta name="keywords" content="{{meta_keywords}}">
    <title>{{title}} | FixItForUs</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>{{default_css}}</style>
    <style>{{custom_css}}</style>
</head>
<body>
    {{header}}
    <main>
        {{breadcrumbs}}
        {{content}}
    </main>
    {{footer}}
    <script>{{default_js}}</script>
    <script>{{custom_js}}</script>
</body>
</html>
HTML;

        $landingTemplate = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{meta_description}}">
    <meta name="keywords" content="{{meta_keywords}}">
    <title>{{title}} | FixItForUs</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>{{default_css}}</style>
    <style>{{custom_css}}</style>
</head>
<body class="landing-page">
    {{header}}
    <main class="landing-main">
        {{content}}
    </main>
    {{footer}}
    <script>{{default_js}}</script>
    <script>{{custom_js}}</script>
</body>
</html>
HTML;

        $defaultCss = <<<'CSS'
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'IBM Plex Sans', sans-serif;
    line-height: 1.6;
    color: #333;
    background-color: #fff;
}

main {
    max-width: 1200px;
    margin: 0 auto;
    padding: 2rem;
}

h1, h2, h3, h4, h5, h6 {
    font-family: 'Bebas Neue', sans-serif;
    margin-bottom: 1rem;
}

a {
    color: #0066cc;
    text-decoration: none;
}

a:hover {
    text-decoration: underline;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 1rem;
}
CSS;

        $templates = [
            [
                'name' => 'Default',
                'slug' => 'default',
                'description' => 'Default page template with header, content, and footer',
                'structure' => $defaultTemplate,
                'default_css' => $defaultCss,
                'default_js' => '',
                'is_active' => 1,
            ],
            [
                'name' => 'Landing Page',
                'slug' => 'landing',
                'description' => 'Full-width landing page template without sidebar',
                'structure' => $landingTemplate,
                'default_css' => $defaultCss,
                'default_js' => '',
                'is_active' => 1,
            ],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO {$this->table('templates')}
            (name, slug, description, structure, default_css, default_js, is_active, created_at, updated_at)
            VALUES (:name, :slug, :description, :structure, :default_css, :default_js, :is_active, :now, :now)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                structure = VALUES(structure),
                default_css = VALUES(default_css),
                default_js = VALUES(default_js),
                is_active = VALUES(is_active),
                updated_at = VALUES(updated_at)
        ");

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($templates as $template) {
            $stmt->execute([
                'name' => $template['name'],
                'slug' => $template['slug'],
                'description' => $template['description'],
                'structure' => $template['structure'],
                'default_css' => $template['default_css'],
                'default_js' => $template['default_js'],
                'is_active' => $template['is_active'],
                'now' => $now,
            ]);
        }
    }

    /**
     * Seed default components
     */
    private function seedComponents(): void
    {
        $pdo = $this->connection->pdo();

        $headerHtml = <<<'HTML'
<header class="site-header">
    <div class="container">
        <div class="header-inner">
            <a href="/" class="logo">
                <span class="logo-text">FixItForUs</span>
            </a>
            <nav class="main-nav">
                <ul>
                    <li><a href="/">Home</a></li>
                    <li><a href="/services">Services</a></li>
                    <li><a href="/about">About</a></li>
                    <li><a href="/contact">Contact</a></li>
                </ul>
            </nav>
            <div class="header-cta">
                <a href="tel:+16162007121" class="phone-link">(616) 200-7121</a>
                <a href="/book" class="btn btn-primary">Book Service</a>
            </div>
        </div>
    </div>
</header>
HTML;

        $headerCss = <<<'CSS'
.site-header {
    background-color: #1a1a2e;
    color: #fff;
    padding: 1rem 0;
    position: sticky;
    top: 0;
    z-index: 1000;
}

.header-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.logo-text {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 2rem;
    color: #fff;
    text-decoration: none;
}

.main-nav ul {
    display: flex;
    list-style: none;
    gap: 2rem;
}

.main-nav a {
    color: #fff;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.2s;
}

.main-nav a:hover {
    color: #ffd700;
}

.header-cta {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.phone-link {
    color: #ffd700;
    font-weight: 600;
}

.btn-primary {
    background-color: #ffd700;
    color: #1a1a2e;
    padding: 0.75rem 1.5rem;
    border-radius: 4px;
    font-weight: 600;
    text-decoration: none;
    transition: background-color 0.2s;
}

.btn-primary:hover {
    background-color: #ffed4a;
    text-decoration: none;
}

@media (max-width: 768px) {
    .main-nav {
        display: none;
    }
}
CSS;

        $footerHtml = <<<'HTML'
<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <span class="logo-text">FixItForUs</span>
                <p>Mobile auto repair services that come to you. Quality work, fair prices, convenient service.</p>
            </div>
            <div class="footer-nav">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="/">Home</a></li>
                    <li><a href="/services">Services</a></li>
                    <li><a href="/about">About Us</a></li>
                    <li><a href="/contact">Contact</a></li>
                </ul>
            </div>
            <div class="footer-contact">
                <h4>Contact Us</h4>
                <p><a href="tel:+16162007121">(616) 200-7121</a></p>
                <p><a href="mailto:info@fixitforus.com">info@fixitforus.com</a></p>
                <p>Grand Rapids, MI</p>
            </div>
            <div class="footer-hours">
                <h4>Hours</h4>
                <p>Monday - Friday: 8am - 6pm</p>
                <p>Saturday: 9am - 4pm</p>
                <p>Sunday: Closed</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; {{year}} FixItForUs. All rights reserved.</p>
        </div>
    </div>
</footer>
HTML;

        $footerCss = <<<'CSS'
.site-footer {
    background-color: #1a1a2e;
    color: #fff;
    padding: 3rem 0 1rem;
    margin-top: 4rem;
}

.footer-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 2rem;
}

.footer-brand .logo-text {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.5rem;
    display: block;
    margin-bottom: 1rem;
}

.footer-brand p {
    color: #ccc;
    font-size: 0.9rem;
}

.site-footer h4 {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.25rem;
    margin-bottom: 1rem;
    color: #ffd700;
}

.site-footer ul {
    list-style: none;
}

.site-footer ul li {
    margin-bottom: 0.5rem;
}

.site-footer a {
    color: #ccc;
    text-decoration: none;
    transition: color 0.2s;
}

.site-footer a:hover {
    color: #ffd700;
}

.footer-contact p,
.footer-hours p {
    color: #ccc;
    font-size: 0.9rem;
    margin-bottom: 0.5rem;
}

.footer-bottom {
    border-top: 1px solid #333;
    margin-top: 2rem;
    padding-top: 1rem;
    text-align: center;
    color: #666;
    font-size: 0.85rem;
}

@media (max-width: 768px) {
    .footer-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 480px) {
    .footer-grid {
        grid-template-columns: 1fr;
    }
}
CSS;

        $components = [
            [
                'name' => 'Main Header',
                'slug' => 'main-header',
                'type' => 'header',
                'description' => 'Primary site header with navigation',
                'content' => $headerHtml,
                'css' => $headerCss,
                'javascript' => '',
                'is_active' => 1,
                'cache_ttl' => 86400,
            ],
            [
                'name' => 'Main Footer',
                'slug' => 'main-footer',
                'type' => 'footer',
                'description' => 'Primary site footer with contact info and links',
                'content' => $footerHtml,
                'css' => $footerCss,
                'javascript' => '',
                'is_active' => 1,
                'cache_ttl' => 86400,
            ],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO {$this->table('components')}
            (name, slug, type, description, content, css, javascript, is_active, cache_ttl, created_at, updated_at)
            VALUES (:name, :slug, :type, :description, :content, :css, :javascript, :is_active, :cache_ttl, :now, :now)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                type = VALUES(type),
                description = VALUES(description),
                content = VALUES(content),
                css = VALUES(css),
                javascript = VALUES(javascript),
                is_active = VALUES(is_active),
                cache_ttl = VALUES(cache_ttl),
                updated_at = VALUES(updated_at)
        ");

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($components as $component) {
            $stmt->execute([
                'name' => $component['name'],
                'slug' => $component['slug'],
                'type' => $component['type'],
                'description' => $component['description'],
                'content' => $component['content'],
                'css' => $component['css'],
                'javascript' => $component['javascript'],
                'is_active' => $component['is_active'],
                'cache_ttl' => $component['cache_ttl'],
                'now' => $now,
            ]);
        }
    }

    /**
     * Seed sample pages
     */
    private function seedSamplePages(): void
    {
        $pdo = $this->connection->pdo();

        // Get the default template ID
        $templateStmt = $pdo->prepare("SELECT id FROM {$this->table('templates')} WHERE slug = :slug LIMIT 1");
        $templateStmt->execute(['slug' => 'default']);
        $templateId = $templateStmt->fetchColumn();

        // Get header and footer component IDs
        $componentStmt = $pdo->prepare("SELECT id FROM {$this->table('components')} WHERE slug = :slug LIMIT 1");

        $componentStmt->execute(['slug' => 'main-header']);
        $headerId = $componentStmt->fetchColumn();

        $componentStmt->execute(['slug' => 'main-footer']);
        $footerId = $componentStmt->fetchColumn();

        $homeContent = <<<'HTML'
<section class="hero">
    <div class="container">
        <h1>Mobile Auto Repair That Comes to You</h1>
        <p class="lead">Skip the shop. Get professional auto repair and maintenance services at your home, office, or anywhere you need us.</p>
        <div class="hero-cta">
            <a href="/book" class="btn btn-primary btn-lg">Book Your Service</a>
            <a href="/services" class="btn btn-outline">View Services</a>
        </div>
    </div>
</section>

<section class="features">
    <div class="container">
        <h2>Why Choose FixItForUs?</h2>
        <div class="features-grid">
            <div class="feature">
                <h3>Convenience</h3>
                <p>We come to you - home, office, or anywhere. No more waiting rooms or arranging rides.</p>
            </div>
            <div class="feature">
                <h3>Expert Technicians</h3>
                <p>Our certified mechanics bring years of experience and the right tools for every job.</p>
            </div>
            <div class="feature">
                <h3>Fair Pricing</h3>
                <p>Transparent pricing with no hidden fees. Get a quote before we start any work.</p>
            </div>
            <div class="feature">
                <h3>Quality Parts</h3>
                <p>We use only quality OEM and aftermarket parts, backed by warranty.</p>
            </div>
        </div>
    </div>
</section>

<section class="services-preview">
    <div class="container">
        <h2>Our Services</h2>
        <div class="services-grid">
            <div class="service-card">
                <h3>Oil Changes</h3>
                <p>Quick, professional oil changes at your location.</p>
            </div>
            <div class="service-card">
                <h3>Brake Service</h3>
                <p>Brake inspections, pad replacement, and rotor service.</p>
            </div>
            <div class="service-card">
                <h3>Diagnostics</h3>
                <p>Computer diagnostics and troubleshooting for all makes and models.</p>
            </div>
            <div class="service-card">
                <h3>General Repair</h3>
                <p>From tune-ups to major repairs, we handle it all.</p>
            </div>
        </div>
        <div class="text-center">
            <a href="/services" class="btn btn-primary">View All Services</a>
        </div>
    </div>
</section>
HTML;

        $homeCustomCss = <<<'CSS'
.hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    color: #fff;
    padding: 6rem 0;
    text-align: center;
}

.hero h1 {
    font-size: 3.5rem;
    margin-bottom: 1rem;
}

.lead {
    font-size: 1.25rem;
    max-width: 600px;
    margin: 0 auto 2rem;
    color: #ccc;
}

.hero-cta {
    display: flex;
    gap: 1rem;
    justify-content: center;
}

.btn-lg {
    padding: 1rem 2rem;
    font-size: 1.1rem;
}

.btn-outline {
    border: 2px solid #ffd700;
    color: #ffd700;
    padding: 0.75rem 1.5rem;
    border-radius: 4px;
}

.btn-outline:hover {
    background-color: #ffd700;
    color: #1a1a2e;
    text-decoration: none;
}

.features, .services-preview {
    padding: 4rem 0;
}

.features {
    background-color: #f8f9fa;
}

.features h2, .services-preview h2 {
    text-align: center;
    font-size: 2.5rem;
    margin-bottom: 3rem;
}

.features-grid, .services-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 2rem;
}

.feature, .service-card {
    background: #fff;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.feature h3, .service-card h3 {
    color: #1a1a2e;
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}

.text-center {
    text-align: center;
    margin-top: 2rem;
}

@media (max-width: 992px) {
    .features-grid, .services-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 576px) {
    .features-grid, .services-grid {
        grid-template-columns: 1fr;
    }

    .hero h1 {
        font-size: 2.5rem;
    }

    .hero-cta {
        flex-direction: column;
        align-items: center;
    }
}
CSS;

        $pages = [
            [
                'slug' => 'home',
                'title' => 'Home',
                'meta_description' => 'FixItForUs - Mobile auto repair services that come to you. Quality work, fair prices, convenient service in Grand Rapids, MI.',
                'meta_keywords' => 'mobile auto repair, car repair, oil change, brake service, Grand Rapids, Michigan',
                'content' => $homeContent,
                'custom_css' => $homeCustomCss,
                'custom_js' => '',
                'is_published' => 1,
                'sort_order' => 1,
            ],
            [
                'slug' => 'services',
                'title' => 'Our Services',
                'meta_description' => 'Professional mobile auto repair services including oil changes, brake service, diagnostics, and more.',
                'meta_keywords' => 'auto repair services, oil change, brake repair, car diagnostics',
                'content' => '<section class="page-content"><div class="container"><h1>Our Services</h1><p>Complete list of our mobile auto repair services.</p></div></section>',
                'custom_css' => '.page-content { padding: 4rem 0; }',
                'custom_js' => '',
                'is_published' => 1,
                'sort_order' => 2,
            ],
            [
                'slug' => 'about',
                'title' => 'About Us',
                'meta_description' => 'Learn about FixItForUs and our mission to provide convenient, quality mobile auto repair services.',
                'meta_keywords' => 'about us, mobile mechanic, auto repair company',
                'content' => '<section class="page-content"><div class="container"><h1>About FixItForUs</h1><p>Our story and mission.</p></div></section>',
                'custom_css' => '.page-content { padding: 4rem 0; }',
                'custom_js' => '',
                'is_published' => 1,
                'sort_order' => 3,
            ],
            [
                'slug' => 'contact',
                'title' => 'Contact Us',
                'meta_description' => 'Contact FixItForUs for mobile auto repair services. Call (616) 200-7121 or email us.',
                'meta_keywords' => 'contact, phone, email, location',
                'content' => '<section class="page-content"><div class="container"><h1>Contact Us</h1><p>Get in touch with our team.</p></div></section>',
                'custom_css' => '.page-content { padding: 4rem 0; }',
                'custom_js' => '',
                'is_published' => 1,
                'sort_order' => 4,
            ],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO {$this->table('pages')}
            (slug, title, meta_description, meta_keywords, template_id, content,
             custom_css, custom_js, header_component_id, footer_component_id,
             is_published, sort_order, created_at, updated_at)
            VALUES (:slug, :title, :meta_description, :meta_keywords, :template_id, :content,
                    :custom_css, :custom_js, :header_component_id, :footer_component_id,
                    :is_published, :sort_order, :now, :now)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                meta_description = VALUES(meta_description),
                meta_keywords = VALUES(meta_keywords),
                template_id = VALUES(template_id),
                content = VALUES(content),
                custom_css = VALUES(custom_css),
                custom_js = VALUES(custom_js),
                header_component_id = VALUES(header_component_id),
                footer_component_id = VALUES(footer_component_id),
                is_published = VALUES(is_published),
                sort_order = VALUES(sort_order),
                updated_at = VALUES(updated_at)
        ");

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($pages as $page) {
            $stmt->execute([
                'slug' => $page['slug'],
                'title' => $page['title'],
                'meta_description' => $page['meta_description'],
                'meta_keywords' => $page['meta_keywords'],
                'template_id' => $templateId ?: null,
                'content' => $page['content'],
                'custom_css' => $page['custom_css'],
                'custom_js' => $page['custom_js'],
                'header_component_id' => $headerId ?: null,
                'footer_component_id' => $footerId ?: null,
                'is_published' => $page['is_published'],
                'sort_order' => $page['sort_order'],
                'now' => $now,
            ]);
        }
    }
}

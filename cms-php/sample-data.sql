-- FixItForUs CMS Sample Data
-- Run this after schema.sql to populate initial content

USE `fixitforus_cms`;

-- =============================================
-- Sample Header Component
-- =============================================
INSERT INTO `components` (`name`, `slug`, `type`, `description`, `content`, `css`, `javascript`, `is_active`, `cache_ttl`) VALUES
('Main Header', 'main-header', 'header', 'Site-wide header with navigation', '
<header class="site-header">
    <div class="header-container">
        <a href="/" class="logo">
            <span class="logo-text">FixIt<span class="accent">ForUs</span></span>
        </a>

        <nav class="main-nav" id="main-nav">
            <ul class="nav-list">
                <li class="nav-item has-dropdown">
                    <a href="/service-list" class="nav-link">Services</a>
                    <ul class="dropdown-menu">
                        <li><a href="/service-list/ac-heat/">AC & Heat</a></li>
                        <li><a href="/service-list/batteries-charging/">Batteries & Charging</a></li>
                        <li><a href="/service-list/brake-system/">Brake System</a></li>
                        <li><a href="/service-list/suspension/">Suspension</a></li>
                        <li><a href="/service-list/cooling-system-2/">Cooling System</a></li>
                    </ul>
                </li>
                <li class="nav-item has-dropdown">
                    <a href="/repair-service-area" class="nav-link">Service Areas</a>
                    <ul class="dropdown-menu">
                        <li><a href="/repair-service-area/grand-rapids-mobile-mechanic/">Grand Rapids</a></li>
                        <li><a href="/repair-service-area/kentwood-mobile-mechanic/">Kentwood</a></li>
                        <li><a href="/repair-service-area/wyoming-mobile-mechanic/">Wyoming</a></li>
                        <li><a href="/repair-service-area/walker-auto-repair/">Walker</a></li>
                    </ul>
                </li>
                <li class="nav-item"><a href="/about-our-company/rates/" class="nav-link">Rates</a></li>
                <li class="nav-item"><a href="/fleet-solutions/" class="nav-link">Fleet</a></li>
                <li class="nav-item"><a href="/blog/" class="nav-link">Blog</a></li>
            </ul>
        </nav>

        <div class="header-cta">
            <a href="tel:+16162007121" class="phone-link">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                (616) 200-7121
            </a>
            <a href="/get-estimate" class="btn-cta">Get Estimate</a>
        </div>

        <button class="mobile-toggle" id="mobile-toggle" aria-label="Toggle menu">
            <span></span>
            <span></span>
            <span></span>
        </button>
    </div>
</header>
', '
.site-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    background: rgba(13, 15, 18, 0.95);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.header-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1rem 2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.logo {
    text-decoration: none;
}

.logo-text {
    font-family: "Bebas Neue", sans-serif;
    font-size: 1.75rem;
    color: #f5f5f5;
    letter-spacing: 1px;
}

.logo-text .accent {
    color: #ff6b2c;
}

.main-nav {
    display: flex;
}

.nav-list {
    display: flex;
    list-style: none;
    margin: 0;
    padding: 0;
    gap: 2rem;
}

.nav-link {
    color: #f5f5f5;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.2s;
}

.nav-link:hover {
    color: #ff6b2c;
}

.nav-item.has-dropdown {
    position: relative;
}

.dropdown-menu {
    position: absolute;
    top: 100%;
    left: 0;
    background: #1a1d23;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    padding: 0.5rem 0;
    min-width: 200px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(10px);
    transition: all 0.2s;
    list-style: none;
}

.nav-item.has-dropdown:hover .dropdown-menu {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.dropdown-menu a {
    display: block;
    padding: 0.5rem 1rem;
    color: #a0a0a0;
    text-decoration: none;
    transition: all 0.2s;
}

.dropdown-menu a:hover {
    background: rgba(255, 107, 44, 0.1);
    color: #ff6b2c;
}

.header-cta {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.phone-link {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #f5f5f5;
    text-decoration: none;
    font-weight: 500;
}

.phone-link:hover {
    color: #ff6b2c;
}

.btn-cta {
    background: #ff6b2c;
    color: #fff;
    padding: 0.75rem 1.5rem;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    transition: background 0.2s;
}

.btn-cta:hover {
    background: #ff8a55;
}

.mobile-toggle {
    display: none;
    flex-direction: column;
    gap: 5px;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0.5rem;
}

.mobile-toggle span {
    width: 24px;
    height: 2px;
    background: #f5f5f5;
    transition: all 0.2s;
}

@media (max-width: 992px) {
    .main-nav {
        position: fixed;
        top: 70px;
        left: 0;
        right: 0;
        background: #0d0f12;
        padding: 1rem;
        transform: translateX(-100%);
        transition: transform 0.3s;
    }

    .main-nav.open {
        transform: translateX(0);
    }

    .nav-list {
        flex-direction: column;
        gap: 0;
    }

    .nav-link {
        display: block;
        padding: 1rem;
    }

    .dropdown-menu {
        position: static;
        opacity: 1;
        visibility: visible;
        transform: none;
        background: transparent;
        border: none;
        padding-left: 1rem;
    }

    .header-cta {
        display: none;
    }

    .mobile-toggle {
        display: flex;
    }
}
', '
document.getElementById("mobile-toggle")?.addEventListener("click", function() {
    document.getElementById("main-nav")?.classList.toggle("open");
});
', 1, 3600);

-- =============================================
-- Sample Footer Component
-- =============================================
INSERT INTO `components` (`name`, `slug`, `type`, `description`, `content`, `css`, `javascript`, `is_active`, `cache_ttl`) VALUES
('Main Footer', 'main-footer', 'footer', 'Site-wide footer with contact info and links', '
<footer class="site-footer">
    <div class="footer-container">
        <div class="footer-grid">
            <!-- Company Info -->
            <div class="footer-section">
                <h3 class="footer-logo">FixIt<span>ForUs</span></h3>
                <p class="footer-tagline">Mobile Auto Repair Services in Grand Rapids and surrounding areas.</p>
                <div class="footer-contact">
                    <a href="tel:+16162007121" class="contact-link">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                        (616) 200-7121
                    </a>
                    <a href="mailto:info@fixitforus.com" class="contact-link">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        info@fixitforus.com
                    </a>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="footer-section">
                <h4 class="footer-title">Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="/get-estimate">Get Estimate</a></li>
                    <li><a href="/book-appointment">Book Appointment</a></li>
                    <li><a href="/about-our-company/rates/">Our Rates</a></li>
                    <li><a href="/about-our-company/financing/">Financing</a></li>
                    <li><a href="/blog/">Blog</a></li>
                </ul>
            </div>

            <!-- Services -->
            <div class="footer-section">
                <h4 class="footer-title">Services</h4>
                <ul class="footer-links">
                    <li><a href="/service-list/brake-system/">Brake Repair</a></li>
                    <li><a href="/service-list/batteries-charging/">Battery Service</a></li>
                    <li><a href="/service-list/ac-heat/">AC & Heat</a></li>
                    <li><a href="/service-list/suspension/">Suspension</a></li>
                    <li><a href="/service-list/cooling-system-2/">Cooling System</a></li>
                </ul>
            </div>

            <!-- Service Areas -->
            <div class="footer-section">
                <h4 class="footer-title">Service Areas</h4>
                <ul class="footer-links">
                    <li><a href="/repair-service-area/grand-rapids-mobile-mechanic/">Grand Rapids</a></li>
                    <li><a href="/repair-service-area/kentwood-mobile-mechanic/">Kentwood</a></li>
                    <li><a href="/repair-service-area/wyoming-mobile-mechanic/">Wyoming</a></li>
                    <li><a href="/repair-service-area/walker-auto-repair/">Walker</a></li>
                    <li><a href="/repair-service-area/muskegon-mobile-mechanic/">Muskegon</a></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; 2024 FixItForUs. All rights reserved.</p>
            <div class="footer-legal">
                <a href="/terms-and-conditions/">Terms & Conditions</a>
                <a href="/about-our-company/legal-disclosure/">Legal Disclosure</a>
            </div>
        </div>
    </div>
</footer>
', '
.site-footer {
    background: #0a0c0f;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    padding: 4rem 0 2rem;
}

.footer-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 2rem;
}

.footer-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr 1fr 1fr;
    gap: 3rem;
    margin-bottom: 3rem;
}

.footer-logo {
    font-family: "Bebas Neue", sans-serif;
    font-size: 1.5rem;
    color: #f5f5f5;
    margin-bottom: 1rem;
}

.footer-logo span {
    color: #ff6b2c;
}

.footer-tagline {
    color: #a0a0a0;
    margin-bottom: 1.5rem;
    line-height: 1.6;
}

.footer-contact {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.contact-link {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #f5f5f5;
    text-decoration: none;
    transition: color 0.2s;
}

.contact-link:hover {
    color: #ff6b2c;
}

.footer-title {
    font-family: "Bebas Neue", sans-serif;
    font-size: 1.1rem;
    color: #f5f5f5;
    margin-bottom: 1rem;
    letter-spacing: 1px;
}

.footer-links {
    list-style: none;
    padding: 0;
    margin: 0;
}

.footer-links li {
    margin-bottom: 0.5rem;
}

.footer-links a {
    color: #a0a0a0;
    text-decoration: none;
    transition: color 0.2s;
}

.footer-links a:hover {
    color: #ff6b2c;
}

.footer-bottom {
    padding-top: 2rem;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #666;
    font-size: 0.9rem;
}

.footer-legal {
    display: flex;
    gap: 1.5rem;
}

.footer-legal a {
    color: #666;
    text-decoration: none;
    transition: color 0.2s;
}

.footer-legal a:hover {
    color: #ff6b2c;
}

@media (max-width: 992px) {
    .footer-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 576px) {
    .footer-grid {
        grid-template-columns: 1fr;
    }

    .footer-bottom {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }
}
', '', 1, 3600);

-- =============================================
-- Sample Call-to-Action Component
-- =============================================
INSERT INTO `components` (`name`, `slug`, `type`, `description`, `content`, `css`, `javascript`, `is_active`, `cache_ttl`) VALUES
('CTA Banner', 'cta-banner', 'widget', 'Call-to-action banner for getting an estimate', '
<section class="cta-section">
    <div class="cta-container">
        <div class="cta-content">
            <h2 class="cta-title">Ready to Get Your Car Fixed?</h2>
            <p class="cta-text">Our mobile mechanics come to you. No towing required. Get a free estimate today!</p>
        </div>
        <div class="cta-buttons">
            <a href="/get-estimate" class="btn-primary">Get Free Estimate</a>
            <a href="tel:+16162007121" class="btn-secondary">Call (616) 200-7121</a>
        </div>
    </div>
</section>
', '
.cta-section {
    background: linear-gradient(135deg, #ff6b2c 0%, #ff8a55 100%);
    padding: 4rem 2rem;
}

.cta-container {
    max-width: 1200px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 2rem;
}

.cta-title {
    font-family: "Bebas Neue", sans-serif;
    font-size: 2.5rem;
    color: #fff;
    margin-bottom: 0.5rem;
}

.cta-text {
    color: rgba(255, 255, 255, 0.9);
    font-size: 1.1rem;
}

.cta-buttons {
    display: flex;
    gap: 1rem;
    flex-shrink: 0;
}

.cta-section .btn-primary {
    background: #fff;
    color: #ff6b2c;
    padding: 1rem 2rem;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    transition: transform 0.2s;
}

.cta-section .btn-primary:hover {
    transform: translateY(-2px);
}

.cta-section .btn-secondary {
    background: transparent;
    color: #fff;
    padding: 1rem 2rem;
    border: 2px solid #fff;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.cta-section .btn-secondary:hover {
    background: #fff;
    color: #ff6b2c;
}

@media (max-width: 768px) {
    .cta-container {
        flex-direction: column;
        text-align: center;
    }

    .cta-buttons {
        flex-direction: column;
        width: 100%;
    }

    .cta-section .btn-primary,
    .cta-section .btn-secondary {
        width: 100%;
        text-align: center;
    }
}
', '', 1, 3600);

-- =============================================
-- Sample Home Page
-- =============================================
INSERT INTO `pages` (`slug`, `title`, `meta_description`, `meta_keywords`, `template_id`, `content`, `is_published`, `cache_ttl`) VALUES
('home', 'Mobile Auto Repair Services in Grand Rapids', 'Professional mobile mechanic services in Grand Rapids and surrounding areas. We come to you! Battery replacement, brake repair, and more.', 'mobile mechanic, auto repair, Grand Rapids, car repair, mobile auto repair', 1, '
<section class="hero-section">
    <div class="hero-container">
        <div class="hero-content">
            <h1 class="hero-title">Mobile Auto Repair <span>That Comes to You</span></h1>
            <p class="hero-text">Professional auto repair services at your location. No towing needed. Serving Grand Rapids and surrounding areas.</p>
            <div class="hero-buttons">
                <a href="/get-estimate" class="btn-primary">Get Free Estimate</a>
                <a href="tel:+16162007121" class="btn-outline">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                    Call Now
                </a>
            </div>
        </div>
    </div>
</section>

<section class="services-preview">
    <div class="section-container">
        <h2 class="section-title">Our Services</h2>
        <div class="services-grid">
            <a href="/service-list/batteries-charging/" class="service-card">
                <div class="service-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="6" width="18" height="12" rx="2" ry="2"></rect><line x1="23" y1="13" x2="23" y2="11"></line></svg>
                </div>
                <h3>Battery Service</h3>
                <p>Testing, replacement, and jump starts at your location.</p>
            </a>
            <a href="/service-list/brake-system/" class="service-card">
                <div class="service-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>
                </div>
                <h3>Brake Repair</h3>
                <p>Pads, rotors, calipers, and complete brake system service.</p>
            </a>
            <a href="/service-list/ac-heat/" class="service-card">
                <div class="service-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"></path></svg>
                </div>
                <h3>AC & Heat</h3>
                <p>AC recharge, compressor repair, and heating system fixes.</p>
            </a>
            <a href="/service-list/suspension/" class="service-card">
                <div class="service-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                </div>
                <h3>Suspension</h3>
                <p>Struts, shocks, control arms, and alignment issues.</p>
            </a>
        </div>
        <div class="services-cta">
            <a href="/service-list" class="btn-secondary">View All Services</a>
        </div>
    </div>
</section>

{{component:cta-banner}}
', 1, 3600);

-- Update template with default CSS for the home page
UPDATE `templates` SET `default_css` = '
:root {
    --bg-primary: #0d0f12;
    --bg-secondary: #1a1d23;
    --accent: #ff6b2c;
    --accent-hover: #ff8a55;
    --text-primary: #f5f5f5;
    --text-secondary: #a0a0a0;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: "IBM Plex Sans", -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--bg-primary);
    color: var(--text-primary);
    line-height: 1.6;
    padding-top: 70px;
}

.hero-section {
    min-height: 70vh;
    display: flex;
    align-items: center;
    background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 100%);
    padding: 4rem 2rem;
}

.hero-container {
    max-width: 1200px;
    margin: 0 auto;
}

.hero-title {
    font-family: "Bebas Neue", sans-serif;
    font-size: 4rem;
    line-height: 1.1;
    margin-bottom: 1.5rem;
}

.hero-title span {
    color: var(--accent);
}

.hero-text {
    font-size: 1.25rem;
    color: var(--text-secondary);
    max-width: 600px;
    margin-bottom: 2rem;
}

.hero-buttons {
    display: flex;
    gap: 1rem;
}

.btn-primary {
    background: var(--accent);
    color: #fff;
    padding: 1rem 2rem;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    transition: background 0.2s;
}

.btn-primary:hover {
    background: var(--accent-hover);
}

.btn-outline {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    border: 2px solid var(--text-primary);
    color: var(--text-primary);
    padding: 1rem 2rem;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-outline:hover {
    background: var(--text-primary);
    color: var(--bg-primary);
}

.btn-secondary {
    background: transparent;
    border: 2px solid var(--accent);
    color: var(--accent);
    padding: 0.875rem 1.75rem;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-secondary:hover {
    background: var(--accent);
    color: #fff;
}

.services-preview {
    padding: 5rem 2rem;
}

.section-container {
    max-width: 1200px;
    margin: 0 auto;
}

.section-title {
    font-family: "Bebas Neue", sans-serif;
    font-size: 2.5rem;
    text-align: center;
    margin-bottom: 3rem;
}

.services-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
    margin-bottom: 3rem;
}

.service-card {
    background: var(--bg-secondary);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 2rem;
    text-decoration: none;
    transition: all 0.3s;
}

.service-card:hover {
    transform: translateY(-5px);
    border-color: var(--accent);
}

.service-icon {
    color: var(--accent);
    margin-bottom: 1rem;
}

.service-card h3 {
    color: var(--text-primary);
    margin-bottom: 0.5rem;
}

.service-card p {
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.services-cta {
    text-align: center;
}

.breadcrumbs {
    padding: 1rem 2rem;
    max-width: 1200px;
    margin: 0 auto;
}

.breadcrumbs ol {
    display: flex;
    list-style: none;
    gap: 0.5rem;
    font-size: 0.9rem;
}

.breadcrumbs a {
    color: var(--text-secondary);
    text-decoration: none;
}

.breadcrumbs a:hover {
    color: var(--accent);
}

.breadcrumbs .separator {
    color: var(--text-secondary);
}

@media (max-width: 992px) {
    .services-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 576px) {
    .hero-title {
        font-size: 2.5rem;
    }

    .hero-buttons {
        flex-direction: column;
    }

    .services-grid {
        grid-template-columns: 1fr;
    }
}
' WHERE `slug` = 'default';

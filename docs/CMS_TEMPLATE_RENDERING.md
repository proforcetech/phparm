# CMS Template Rendering System

## Overview

The PHPArm CMS includes a complete template rendering system that allows you to create dynamic pages using templates, components, and custom styling.

## Architecture

The system consists of four main parts:

1. **Templates** - Define the overall page structure with placeholders
2. **Components** - Reusable pieces of content (headers, footers, widgets)
3. **Pages** - Individual content pages that use templates and components
4. **Rendering Service** - Combines templates, components, and pages into final HTML

## How It Works

### 1. Create Components

Components are reusable pieces of content with their own HTML, CSS, and JavaScript.

**Example Header Component:**
```html
<!-- Content -->
<header class="site-header">
    <nav>
        <a href="/">Home</a>
        <a href="/about">About</a>
        <a href="/contact">Contact</a>
    </nav>
</header>
```

**CSS:**
```css
.site-header {
    background: #333;
    color: white;
    padding: 1rem;
}
```

**JavaScript:**
```javascript
console.log('Header loaded');
```

### 2. Create a Template

Templates define the page structure using placeholders that get replaced with actual content.

**Example Template:**
```html
<!DOCTYPE html>
<html lang="en">
<head>
    <title>{{title}} | My Website</title>
    <meta name="description" content="{{meta_description}}">
    <meta name="keywords" content="{{meta_keywords}}">
    <style>{{default_css}}</style>
    <style>{{custom_css}}</style>
</head>
<body>
    {{header}}
    <main>
        {{breadcrumbs}}
        <div class="content">
            {{content}}
        </div>
    </main>
    {{footer}}
    <script>{{default_js}}</script>
    <script>{{custom_js}}</script>
</body>
</html>
```

### 3. Available Placeholders

The rendering service supports these placeholders:

| Placeholder | Description |
|-------------|-------------|
| `{{title}}` | Page title |
| `{{content}}` | Page content (HTML) |
| `{{summary}}` | Page summary |
| `{{meta_title}}` | SEO meta title (defaults to title) |
| `{{meta_description}}` | SEO meta description |
| `{{meta_keywords}}` | SEO meta keywords |
| `{{slug}}` | Page URL slug |
| `{{header}}` | Header component (HTML + CSS + JS) |
| `{{footer}}` | Footer component (HTML + CSS + JS) |
| `{{component:slug}}` | **Dynamic component loading** - Load any component by slug |
| `{{breadcrumbs}}` | Auto-generated breadcrumb navigation |
| `{{default_css}}` | Template-level CSS |
| `{{custom_css}}` | Page-specific CSS |
| `{{default_js}}` | Template-level JavaScript |
| `{{custom_js}}` | Page-specific JavaScript |
| `{{year}}` | Current year (for copyright notices) |

### 3a. Dynamic Component Loading

**NEW FEATURE:** You can now load any component dynamically using `{{component:slug}}` syntax!

**How it works:**
- Use `{{component:slug}}` anywhere in your template
- The system automatically loads the component by its slug
- Components are rendered with their CSS and JavaScript inline
- No database fields or form selectors needed
- Unlimited components per page

**Example Template with Dynamic Components:**
```html
<!DOCTYPE html>
<html lang="en">
<head>
    <title>{{title}}</title>
    <style>{{default_css}}</style>
</head>
<body>
    {{header}}

    <aside class="sidebar">
        {{component:sidebar-nav}}
        {{component:newsletter-signup}}
    </aside>

    <main>
        {{content}}
        {{component:call-to-action}}
    </main>

    {{footer}}
    {{component:chat-widget}}
</body>
</html>
```

**Benefits:**
- **Flexible:** Add components anywhere without changing database schema
- **Reusable:** Create components once, use everywhere by slug
- **Simple:** Just reference the component slug in your template
- **Clean:** No need for additional form fields or database columns

**Example Use Cases:**
- `{{component:sidebar}}` - Sidebar navigation
- `{{component:promo-banner}}` - Promotional banners
- `{{component:social-share}}` - Social sharing buttons
- `{{component:newsletter}}` - Newsletter signup forms
- `{{component:testimonials}}` - Customer testimonials
- `{{component:contact-form}}` - Contact forms
- `{{component:image-gallery}}` - Photo galleries

**What if a component doesn't exist?**
If you reference a component that doesn't exist, the system will insert an HTML comment:
```html
<!-- Component "widget-name" not found -->
```
This won't break your page, but you can check the source to see which components are missing.

### 4. Create a Page

When creating a page, you can:

1. **Select a Template** - Choose which template structure to use
2. **Select Header Component** - Choose which header to display
3. **Select Footer Component** - Choose which footer to display
4. **Add Content** - Write the main page content (HTML)
5. **Add Custom CSS** - Page-specific styles
6. **Add Custom JavaScript** - Page-specific scripts

## Rendering Process

When a user visits a page (e.g., `/about-us`), here's what happens:

1. **Load Page Data** - Fetch the page from database by slug
2. **Load Template** - Fetch the template associated with the page
3. **Load Fixed Components** - Fetch header and footer components (from page settings)
4. **Build Data Array** - Combine all data into a placeholder array:
   ```php
   [
       'title' => 'About Us',
       'content' => '<p>Our company...</p>',
       'header' => '<header>...</header><style>...</style><script>...</script>',
       'footer' => '<footer>...</footer><style>...</style>',
       'default_css' => 'body { font-family: sans-serif; }',
       'custom_css' => '.about-page { background: #f0f0f0; }',
       // ... etc
   ]
   ```
5. **Load Dynamic Components** - Scan template for `{{component:slug}}` patterns and load them
6. **Render Template** - Replace all placeholders in the template with actual values
7. **Cache Result** - Cache the rendered HTML for performance
8. **Return HTML** - Serve the complete page to the user

## API Endpoints

### Get Rendered Page (Public)
```
GET /cms/page/{slug}
```
Returns fully rendered HTML for a published page.

### Preview Page (Admin)
```
GET /api/cms/pages/{id}/preview
```
Returns fully rendered HTML for any page (draft or published). Requires authentication.

### Get Page Data (Admin)
```
GET /api/cms/pages/{id}
```
Returns raw page data as JSON for editing.

## Component Rendering

Components are rendered with their CSS and JavaScript inline:

**Input:**
```php
Component:
  - content: "<div>Hello</div>"
  - css: "div { color: blue; }"
  - javascript: "alert('Hi');"
```

**Output:**
```html
<div>Hello</div>
<style>
div { color: blue; }
</style>
<script>
alert('Hi');
</script>
```

## Caching

The rendering service caches rendered pages for 1 hour by default. Cache is automatically invalidated when:

- Page is updated
- Page is published/unpublished
- Template is modified
- Component is modified

## Example Usage

### 1. Create a Blog Post Template

```html
<!DOCTYPE html>
<html>
<head>
    <title>{{title}} - My Blog</title>
    <meta name="description" content="{{meta_description}}">
    <style>
        body {
            font-family: 'Georgia', serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        {{default_css}}
    </style>
    <style>{{custom_css}}</style>
</head>
<body>
    {{header}}
    <article>
        <h1>{{title}}</h1>
        <div class="summary">{{summary}}</div>
        <div class="content">{{content}}</div>
    </article>
    {{footer}}
    <script>{{default_js}}</script>
    <script>{{custom_js}}</script>
</body>
</html>
```

### 2. Create a Header Component

**Name:** Main Header
**Type:** header
**Content:**
```html
<header class="main-header">
    <div class="logo">My Blog</div>
    <nav>
        <a href="/">Home</a>
        <a href="/about">About</a>
        <a href="/contact">Contact</a>
    </nav>
</header>
```

**CSS:**
```css
.main-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.logo {
    font-size: 1.5rem;
    font-weight: bold;
}
nav a {
    color: white;
    margin: 0 1rem;
    text-decoration: none;
}
```

### 3. Create a Page

- **Title:** Welcome to My Blog
- **Slug:** welcome
- **Template:** Blog Post Template
- **Header Component:** Main Header
- **Footer Component:** Main Footer
- **Content:**
```html
<p>Welcome to my blog where I share thoughts about web development.</p>
<p>Check out my latest posts below!</p>
```

**Custom CSS:**
```css
.content p {
    line-height: 1.8;
    margin-bottom: 1rem;
}
```

### 4. View the Result

Visit `/cms/welcome` and you'll see the fully rendered page with:
- Header component with gradient background
- Your blog post content
- Footer component
- All styling combined
- SEO meta tags
- Everything cached for fast loading

### 5. Using Dynamic Components - Complete Example

Let's create a complete example using dynamic components:

#### Step 1: Create Widget Components

**Newsletter Widget (slug: `newsletter-signup`)**
- Type: widget
- Content:
```html
<div class="newsletter-box">
    <h3>Subscribe to Our Newsletter</h3>
    <form action="/subscribe" method="POST">
        <input type="email" name="email" placeholder="Your email">
        <button type="submit">Subscribe</button>
    </form>
</div>
```
- CSS:
```css
.newsletter-box {
    background: #f8f9fa;
    padding: 2rem;
    border-radius: 8px;
}
```

**Social Share Widget (slug: `social-share`)**
- Type: widget
- Content:
```html
<div class="social-share">
    <a href="#" onclick="shareOnFacebook()">Facebook</a>
    <a href="#" onclick="shareOnTwitter()">Twitter</a>
    <a href="#" onclick="shareOnLinkedIn()">LinkedIn</a>
</div>
```

**Call to Action (slug: `cta-demo`)**
- Type: widget
- Content:
```html
<div class="cta-banner">
    <h2>Ready to Get Started?</h2>
    <p>Sign up for a free demo today!</p>
    <a href="/demo" class="cta-button">Request Demo</a>
</div>
```

#### Step 2: Create Template with Dynamic Components

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <title>{{title}} | My Company</title>
    <meta name="description" content="{{meta_description}}">
    <style>{{default_css}}</style>
    <style>{{custom_css}}</style>
</head>
<body>
    {{header}}

    <div class="layout">
        <aside class="sidebar">
            {{component:newsletter-signup}}
            {{component:social-share}}
        </aside>

        <main class="content">
            {{breadcrumbs}}
            {{content}}
            {{component:cta-demo}}
        </main>
    </div>

    {{footer}}
    <script>{{default_js}}</script>
    <script>{{custom_js}}</script>
</body>
</html>
```

#### Step 3: Create Your Page

- Title: "Our Services"
- Template: (Select the template above)
- Header: (Select your main header)
- Footer: (Select your main footer)
- Content:
```html
<h1>Professional Services</h1>
<p>We offer comprehensive solutions for your business needs.</p>
<ul>
    <li>Consulting</li>
    <li>Development</li>
    <li>Support</li>
</ul>
```

#### Result

When rendered, the page will automatically include:
- Your main header (from header_component_id)
- Newsletter signup widget in the sidebar
- Social share buttons in the sidebar
- Your page content in the main area
- Call-to-action banner after the content
- Your main footer (from footer_component_id)

All without needing any database fields or form selectors for the widgets!

**Key Advantages:**
- ✅ Add `{{component:testimonials}}` to any template instantly
- ✅ Reuse `{{component:newsletter-signup}}` across multiple templates
- ✅ Change a widget once, updates everywhere it's used
- ✅ No code changes needed to add new components
- ✅ Conditionally load components in different templates

## Benefits

1. **Reusability** - Create headers/footers once, use across all pages
2. **Consistency** - All pages using the same template have consistent structure
3. **Flexibility** - Each page can have custom CSS/JS
4. **SEO-Friendly** - Proper meta tags and semantic HTML
5. **Performance** - Rendered pages are cached
6. **Easy Management** - Change header site-wide by editing one component

## Database Structure

### cms_pages
- `template_id` - Which template to use
- `header_component_id` - Which header to display
- `footer_component_id` - Which footer to display
- `custom_css` - Page-specific CSS
- `custom_js` - Page-specific JavaScript
- `content` - Main page content
- Other fields: title, slug, meta fields, etc.

### cms_templates
- `structure` - HTML template with placeholders
- `default_css` - Template-level CSS
- `default_js` - Template-level JavaScript

### cms_components
- `type` - header, footer, navigation, sidebar, widget, custom
- `content` - Component HTML
- `css` - Component CSS
- `javascript` - Component JavaScript

## Service Classes

### CMSRenderingService

Located at: `/src/Services/CMS/CMSRenderingService.php`

**Key Methods:**
- `renderPage(string $slug): ?string` - Render published page by slug
- `renderPageContent(Page $page): ?string` - Render any page object
- `loadTemplate(int $id): ?Template` - Load template by ID
- `loadComponent(int $id): ?Component` - Load component by ID
- `loadComponentBySlug(string $slug): ?Component` - Load component by slug (for dynamic loading)
- `loadDynamicComponents(string $template, array $data): array` - Extract and load all `{{component:*}}` placeholders
- `extractComponentSlugs(string $template): array` - Parse template for component slugs
- `buildPlaceholderData()` - Build array of all placeholder values
- `renderComponent(Component $component): string` - Render component with CSS/JS

### TemplateEngine

Located at: `/src/Support/Notifications/TemplateEngine.php`

**Key Method:**
- `render(string $template, array $data): string` - Replace placeholders with values

Simple placeholder replacement using `strtr()`:
```php
$replacements = [
    '{{title}}' => 'My Page Title',
    '{{content}}' => '<p>Content here</p>',
];
return strtr($template, $replacements);
```

## Tips

1. **Keep Templates Simple** - Focus on structure, not styling
2. **Use Components Wisely** - Create components for truly reusable content
3. **Test Locally** - Use the preview endpoint before publishing
4. **Cache Management** - Clear cache after major changes
5. **Security** - Never put sensitive data in templates or components
6. **Performance** - Minimize custom JS, use async/defer when possible

## Troubleshooting

**Page not rendering?**
- Check that the template is active (`is_active = 1`)
- Verify components are active
- Check database foreign keys are correct

**Styling not showing?**
- Verify CSS is in the correct field (template vs custom)
- Check for CSS syntax errors
- Use browser dev tools to inspect

**Placeholder not replaced?**
- Ensure exact spelling: `{{title}}` not `{{ title }}` or `{title}`
- Check placeholder is defined in buildPlaceholderData()

**Cache issues?**
- Clear cache manually from admin panel
- Check cache service is enabled
- Verify file permissions on cache directory

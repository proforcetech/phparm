-- Migration: Add estimate request form CMS component
-- This creates a reusable component that can be embedded in CMS pages using {{component:estimate-request}}

INSERT INTO cms_components (
    name,
    slug,
    type,
    description,
    content,
    css,
    javascript,
    is_active,
    cache_ttl,
    created_at,
    updated_at
) VALUES (
    'Estimate Request Form',
    'estimate-request',
    'custom',
    'Public-facing estimate request form with vehicle selection, service details, and photo upload',
    '<div data-vue-component="EstimateRequestForm" class="estimate-request-form-container"></div>',
    '/* Ensure form has proper spacing */
.estimate-request-form-container {
    width: 100%;
    max-width: 100%;
    padding: 0;
    margin: 0 auto;
}',
    NULL,
    1,
    0,
    NOW(),
    NOW()
);

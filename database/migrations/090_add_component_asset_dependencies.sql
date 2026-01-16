ALTER TABLE cms_components
    ADD COLUMN css_assets TEXT NULL AFTER javascript,
    ADD COLUMN js_assets TEXT NULL AFTER css_assets;

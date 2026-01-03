-- Add component and custom styling fields to cms_pages
-- Allows pages to specify header/footer components and custom CSS/JS

ALTER TABLE cms_pages
    ADD COLUMN header_component_id INT UNSIGNED NULL AFTER template_id,
    ADD COLUMN footer_component_id INT UNSIGNED NULL AFTER header_component_id,
    ADD COLUMN custom_css TEXT NULL AFTER footer_component_id,
    ADD COLUMN custom_js TEXT NULL AFTER custom_css,
    ADD INDEX idx_cms_pages_header_component (header_component_id),
    ADD INDEX idx_cms_pages_footer_component (footer_component_id),
    ADD CONSTRAINT fk_cms_pages_header_component
        FOREIGN KEY (header_component_id) REFERENCES cms_components(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_cms_pages_footer_component
        FOREIGN KEY (footer_component_id) REFERENCES cms_components(id)
        ON DELETE SET NULL;

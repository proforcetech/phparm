-- Migration: Add scale and options support to inspection items
-- This allows for number scales, Yes/No/N/A, and custom text scales

ALTER TABLE inspection_items
  ADD COLUMN options JSON NULL AFTER default_value COMMENT 'Configuration for scales and select options';

-- Update existing boolean items to support N/A option by changing type
-- Existing boolean items will remain compatible

COMMENT ON COLUMN inspection_items.input_type IS 'Field types: text, textarea, boolean, boolean_na, number, number_scale, select_scale';
COMMENT ON COLUMN inspection_items.options IS 'JSON configuration: {min, max, step} for number_scale, {choices: [...]} for select_scale';

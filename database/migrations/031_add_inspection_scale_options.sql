-- Migration: Add scale and options support to inspection items
-- This allows for number scales, Yes/No/N/A, and custom text scales

ALTER TABLE inspection_items
  ADD COLUMN options JSON NULL AFTER default_value COMMENT 'JSON configuration: {min, max, step} for number_scale, {choices: [...]} for select_scale';

-- Note: input_type supports: text, textarea, boolean, boolean_na, number, number_scale, select_scale
-- Update existing boolean items to support N/A option by changing type
-- Existing boolean items will remain compatible

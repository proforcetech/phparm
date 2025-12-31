-- Add description column to inventory_items
ALTER TABLE inventory_items
    ADD COLUMN description TEXT NULL AFTER name;

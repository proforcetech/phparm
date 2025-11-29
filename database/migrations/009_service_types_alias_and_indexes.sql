-- Add alias metadata and uniqueness constraints for service types
ALTER TABLE service_types
    ADD COLUMN alias VARCHAR(120) NULL AFTER name,
    ADD UNIQUE INDEX uniq_service_types_name (name),
    ADD UNIQUE INDEX uniq_service_types_alias (alias);

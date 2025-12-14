# Database Migrations

This directory contains SQL migration files for the application schema.

## Migration Numbering

Migrations are numbered sequentially (001, 003, 004, etc.). Some numbers are skipped due to consolidation.

## Consolidated Migrations

To reduce redundancy and improve maintainability, related migrations have been consolidated:

### Removed/Consolidated Files

- **006_roles_permissions.sql** - REMOVED (redundant with 001_initial_schema.sql)
- **019_add_appointment_reminder_sent_at.sql** - Consolidated into 020_schema_enhancements.sql
- **023_add_two_factor_columns.sql** - Consolidated into 020_schema_enhancements.sql  
- **024_add_mobile_flags.sql** - Consolidated into 020_schema_enhancements.sql
- **028_add_two_factor_type.sql** - Consolidated into 020_schema_enhancements.sql

### 020_schema_enhancements.sql

This consolidated migration includes:
- Appointment reminder tracking (`reminder_sent_at`)
- Customer vehicle mileage fields (`mileage_in`, `mileage_out`)
- Two-factor authentication (all columns and types)
- Mobile service flags for estimates and invoices

### 027_custom_roles.sql

Introduces a modern role management system with JSON-based permissions, superseding the old `role_permissions` table approach.

## Idempotency

All migrations use `IF NOT EXISTS` clauses to make them idempotent (safe to run multiple times):
- `CREATE TABLE IF NOT EXISTS`
- `CREATE INDEX IF NOT EXISTS`
- `ADD COLUMN IF NOT EXISTS` (MySQL 8.0.29+)

## Migration Order

1. **001_initial_schema.sql** - Core tables (users, customers, vehicles, etc.)
2. **003-008** - Audit logs, notifications, auth tables
3. **012-018** - Credit ledger, reminders, payments, warranties
4. **020** - Consolidated schema enhancements
5. **021-022** - CMS tables
6. **025-026** - Inventory and inspections
7. **027** - Custom roles with JSON permissions

## Running Migrations

Migrations should be applied in numerical order. The application handles this automatically on startup.

## Notes

- System roles (admin, manager, technician, customer) are immutable
- The `custom_roles` table provides flexible permission management
- All timestamps use MySQL's CURRENT_TIMESTAMP functionality
- Foreign keys maintain referential integrity where applicable

# CMS Integration Guide

This CMS is integrated with the main PHPArm application and shares the same database.

## Table Prefix

To avoid conflicts with main application tables, all CMS tables use the `cms_` prefix:

- `cms_users` - CMS admin users (separate from main app users)
- `cms_pages` - Content pages
- `cms_components` - Reusable components (header, footer, etc.)
- `cms_templates` - Page templates/layouts
- `cms_page_components` - Page-component relationships
- `cms_cache` - Database cache
- `cms_settings` - CMS configuration settings
- `cms_activity_log` - Audit trail

## Database Setup

### Option 1: Import with Prefix (Recommended)

Use the provided migration script to create tables with the `cms_` prefix:

```bash
# From the project root
mysql -u phparm -p phparm < database/migrations/cms_setup.sql
```

### Option 2: Manual Setup with sed

If you need to manually create the schema with a different prefix:

```bash
# Replace 'cms_' with your desired prefix
sed 's/CREATE TABLE `/CREATE TABLE `cms_/g' cms-php/schema.sql | \
sed 's/FROM `/FROM `cms_/g' | \
sed 's/JOIN `/JOIN `cms_/g' | \
sed 's/INTO `/INTO `cms_/g' | \
sed 's/DROP TABLE IF EXISTS `/DROP TABLE IF EXISTS `cms_/g' | \
sed 's/ALTER TABLE `/ALTER TABLE `cms_/g' | \
sed 's/REFERENCES `/REFERENCES `cms_/g' > cms-php/schema-prefixed.sql

# Then import
mysql -u phparm -p phparm < cms-php/schema-prefixed.sql
```

## Environment Configuration

The table prefix is configured via the `CMS_TABLE_PREFIX` environment variable.

**In docker-compose.yml:**
```yaml
environment:
  CMS_TABLE_PREFIX: cms_
```

**In .env file:**
```env
CMS_TABLE_PREFIX=cms_
```

## Integrated vs Standalone Mode

### Integrated Mode (Current Setup)

When running within the main PHPArm application:
- Uses main app's database credentials from docker-compose.yml
- Shares the `phparm` database
- Tables are prefixed with `cms_`
- Uses main app's `env()` function

### Standalone Mode

To run the CMS independently:
1. Create a separate database
2. Create `cms-php/.env` file with database credentials
3. Set `CMS_TABLE_PREFIX=` (empty string, no prefix)
4. Import `cms-php/schema.sql` directly
5. Access via `cms-php/admin.php` and `cms-php/index.php`

## Shared Database Considerations

- CMS and main app use the **same database** (`phparm`)
- CMS tables are **prefixed with `cms_`** to avoid conflicts
- Main app tables: `users`, `settings`, `customers`, `invoices`, etc.
- CMS tables: `cms_users`, `cms_settings`, `cms_pages`, etc.
- No overlap - systems coexist safely

## Database Credentials

Both systems use the same credentials:
- **Host**: db (Docker service name)
- **Database**: phparm
- **Username**: phparm
- **Password**: secret

These are set in `docker-compose.yml` and automatically passed to both the main application and CMS.

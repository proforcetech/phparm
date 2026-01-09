# Driver location partitioning & backfill plan

## Goals
- Keep recent driver location queries fast by isolating data in monthly partitions.
- Ensure historical data remains queryable without full table scans.

## Partitioning strategy
- Table: `driver_locations`
- Partition key: `recorded_at`
- Partition naming: `pYYYY_MM` (example: `p2024_09`)
- Safety partition: `pmax`
- Rolling window: create partitions for the previous 12 months and the next 12 months.

## Migration/backfill plan
1. Schedule the migration in a low-traffic window. Partitioning rebuilds the table and will lock writes.
2. Apply migration `081_partition_driver_locations.sql`. It:
   - Converts the primary key to `(id, recorded_at)` to satisfy MySQL partitioning rules.
   - Builds monthly partitions plus `pmax`.
   - Automatically moves existing rows into the correct month partitions.
3. Validate:
   - Confirm partition list with `SHOW CREATE TABLE driver_locations;`
   - Confirm row counts per partition with `SELECT PARTITION_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.PARTITIONS WHERE TABLE_NAME = 'driver_locations';`

## Ongoing maintenance
- Add a new partition each month (and optionally drop old ones) using:
  `ALTER TABLE driver_locations ADD PARTITION (PARTITION pYYYY_MM VALUES LESS THAN ('YYYY-MM-01'));`
- Ensure the application stays within the rolling window created by the migration so queries can target current/previous partitions.

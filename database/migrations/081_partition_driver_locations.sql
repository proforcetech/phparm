-- Partition driver_locations by month for faster dispatch queries.
-- Backfill plan: apply this migration during a low-traffic window so the table can be reorganized
-- and existing rows will be moved into month partitions automatically.

ALTER TABLE driver_locations
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (id, recorded_at);

DROP PROCEDURE IF EXISTS create_driver_locations_partitions;

DELIMITER //
CREATE PROCEDURE create_driver_locations_partitions()
BEGIN
    DECLARE start_date DATE;
    DECLARE end_date DATE;
    DECLARE current_date DATE;
    SET start_date = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 12 MONTH), '%Y-%m-01');
    SET end_date = DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 12 MONTH), '%Y-%m-01');
    SET current_date = start_date;
    SET @sql = 'ALTER TABLE driver_locations PARTITION BY RANGE COLUMNS(recorded_at) (';

    WHILE current_date < end_date DO
        SET @partition_name = DATE_FORMAT(current_date, 'p%Y_%m');
        SET @next_date = DATE_ADD(current_date, INTERVAL 1 MONTH);
        SET @sql = CONCAT(
            @sql,
            'PARTITION ',
            @partition_name,
            ' VALUES LESS THAN (''',
            DATE_FORMAT(@next_date, '%Y-%m-01'),
            '''),'
        );
        SET current_date = @next_date;
    END WHILE;

    SET @sql = CONCAT(@sql, 'PARTITION pmax VALUES LESS THAN (MAXVALUE))');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END//
DELIMITER ;

CALL create_driver_locations_partitions();
DROP PROCEDURE create_driver_locations_partitions;

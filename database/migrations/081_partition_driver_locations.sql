-- Partition driver_locations by month
-- Note: We use the DELIMITER command so the PHP runner knows to split by //

DELIMITER //

-- 1. Adjust Primary Key (One chunk ending in //)
ALTER TABLE driver_locations
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (id, recorded_at)
//

DROP PROCEDURE IF EXISTS create_driver_locations_partitions
//

-- 2. Create the Procedure (The semicolon inside won't break it now)
CREATE PROCEDURE create_driver_locations_partitions()
BEGIN
    DECLARE start_date DATE;
    DECLARE end_date DATE;
    DECLARE current_date_var DATE; 
    
    SET start_date = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 12 MONTH), '%Y-%m-01');
    SET end_date = DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 12 MONTH), '%Y-%m-01');
    SET current_date_var = start_date;
    
    SET @sql = 'ALTER TABLE driver_locations PARTITION BY RANGE COLUMNS(recorded_at) (';

    WHILE current_date_var < end_date DO
        SET @partition_name = DATE_FORMAT(current_date_var, 'p%Y_%m');
        SET @next_date = DATE_ADD(current_date_var, INTERVAL 1 MONTH);
        
        SET @sql = CONCAT(
            @sql,
            'PARTITION ', @partition_name, ' VALUES LESS THAN (''', DATE_FORMAT(@next_date, '%Y-%m-01'), '''),'
        );
        SET current_date_var = @next_date;
    END WHILE;

    SET @sql = CONCAT(@sql, 'PARTITION pmax VALUES LESS THAN (MAXVALUE))');
    
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END
//

-- 3. Run and Cleanup
CALL create_driver_locations_partitions()
//

DROP PROCEDURE create_driver_locations_partitions
//

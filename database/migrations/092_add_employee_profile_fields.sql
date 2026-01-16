-- Ensure employee profile fields exist for HR/payroll workflows.

SET @has_employees_hire_date := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'employees'
      AND column_name = 'hire_date'
);
SET @add_employees_hire_date_sql := IF(@has_employees_hire_date = 0,
    'ALTER TABLE employees ADD COLUMN hire_date DATE NULL',
    'SELECT 1');
PREPARE add_employees_hire_date_stmt FROM @add_employees_hire_date_sql;
EXECUTE add_employees_hire_date_stmt;
DEALLOCATE PREPARE add_employees_hire_date_stmt;

SET @has_employees_emergency_contact := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'employees'
      AND column_name = 'emergency_contact'
);
SET @add_employees_emergency_contact_sql := IF(@has_employees_emergency_contact = 0,
    'ALTER TABLE employees ADD COLUMN emergency_contact TEXT NULL',
    'SELECT 1');
PREPARE add_employees_emergency_contact_stmt FROM @add_employees_emergency_contact_sql;
EXECUTE add_employees_emergency_contact_stmt;
DEALLOCATE PREPARE add_employees_emergency_contact_stmt;

SET @has_employees_pay_structure := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'employees'
      AND column_name = 'pay_structure'
);
SET @add_employees_pay_structure_sql := IF(@has_employees_pay_structure = 0,
    'ALTER TABLE employees ADD COLUMN pay_structure VARCHAR(40) NULL',
    'SELECT 1');
PREPARE add_employees_pay_structure_stmt FROM @add_employees_pay_structure_sql;
EXECUTE add_employees_pay_structure_stmt;
DEALLOCATE PREPARE add_employees_pay_structure_stmt;

SET @has_employees_skills := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'employees'
      AND column_name = 'skills'
);
SET @add_employees_skills_sql := IF(@has_employees_skills = 0,
    'ALTER TABLE employees ADD COLUMN skills JSON NULL',
    'SELECT 1');
PREPARE add_employees_skills_stmt FROM @add_employees_skills_sql;
EXECUTE add_employees_skills_stmt;
DEALLOCATE PREPARE add_employees_skills_stmt;

-- Phase 5.3 of docs/expansion-plan.md — PM generation audit trail.
--
-- Append-only log of every PM that the generator cron has spawned, indexed by
-- schedule_id so Phase 5.4's missed/overdue report can join back to schedule
-- targets and compute compliance percentages. ticket_id is nullable so we
-- can still record a "generation was attempted but failed" row for diagnostics.
--
-- due_at captures what next_due_at was at the moment of generation — this is
-- the authoritative timestamp we compare against for overdue calculation,
-- independent of any later schedule mutations.

CREATE TABLE IF NOT EXISTS pm_generations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NULL,
    due_at DATE NOT NULL,
    generated_at DATETIME NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'generated',
    failure_reason TEXT NULL,
    PRIMARY KEY (id),
    INDEX idx_pm_generations_schedule (schedule_id),
    INDEX idx_pm_generations_plan (plan_id),
    INDEX idx_pm_generations_ticket (ticket_id),
    INDEX idx_pm_generations_due (due_at),
    CONSTRAINT fk_pm_generations_schedule
        FOREIGN KEY (schedule_id) REFERENCES pm_schedules(id) ON DELETE CASCADE,
    CONSTRAINT fk_pm_generations_plan
        FOREIGN KEY (plan_id) REFERENCES pm_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

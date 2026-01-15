-- Track drop-off locations for active driver job offers

ALTER TABLE driver_job_offers
    ADD COLUMN IF NOT EXISTS dropoff_latitude DECIMAL(10,6) NULL,
    ADD COLUMN IF NOT EXISTS dropoff_longitude DECIMAL(10,6) NULL;

CREATE INDEX IF NOT EXISTS idx_driver_job_offers_dropoff
    ON driver_job_offers (driver_profile_id, status, accepted_at);

ALTER TABLE time_entries
    ADD COLUMN en_route_at DATETIME NULL AFTER review_notes,
    ADD COLUMN on_site_at DATETIME NULL AFTER en_route_at,
    ADD COLUMN wrap_up_at DATETIME NULL AFTER on_site_at;

ALTER TABLE time_adjustments
    ADD COLUMN previous_en_route_at DATETIME NULL AFTER previous_ended_at,
    ADD COLUMN previous_on_site_at DATETIME NULL AFTER previous_en_route_at,
    ADD COLUMN previous_wrap_up_at DATETIME NULL AFTER previous_on_site_at,
    ADD COLUMN new_en_route_at DATETIME NULL AFTER new_ended_at,
    ADD COLUMN new_on_site_at DATETIME NULL AFTER new_en_route_at,
    ADD COLUMN new_wrap_up_at DATETIME NULL AFTER new_on_site_at;

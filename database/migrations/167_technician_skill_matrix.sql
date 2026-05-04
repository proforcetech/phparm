-- =============================================================================
-- Migration 167 — Technician Skill Matrix
-- Phase 17 (S11) of docs/woms-expansion-plan.md
--
-- Adds two tables that let managers track which technicians can do which kinds
-- of work, at what proficiency, and when their certifications expire:
--
--   skills            — catalog of trade-specific competencies (e.g. "HVAC
--                       brazing", "Cisco Meraki", "POS terminal swap")
--   user_skills       — m:n join carrying proficiency_level + cert dates
--
-- Skills are scoped to a service_line (the IT skills are not interchangeable
-- with the HVAC skills) but a single skill can be NULL-scoped for cross-trade
-- competencies like "First Aid" or "Customer Service".
--
-- The dispatch board (Phase 17 / M10) consumes user_skills to suggest
-- assignees who match the work order's required_skill_id (added in a later
-- migration when M10 lands).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PART A: skills catalog
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS skills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    service_line_id BIGINT UNSIGNED NULL,
    category VARCHAR(60) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_skills_active (is_active),
    INDEX idx_skills_sort (sort_order),
    INDEX idx_skills_service_line (service_line_id),
    INDEX idx_skills_category (category),
    CONSTRAINT fk_skills_service_line
        FOREIGN KEY (service_line_id) REFERENCES service_lines(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART B: user_skills (m:n with proficiency + cert dates)
-- proficiency_level enum is enforced in PHP (App\Models\UserSkill::PROFICIENCY_*).
-- We use VARCHAR rather than ENUM so we can extend the vocabulary without
-- another migration.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_skills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    skill_id BIGINT UNSIGNED NOT NULL,
    proficiency_level VARCHAR(20) NOT NULL DEFAULT 'competent',
    certified_at DATE NULL,
    expires_at DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_skills_user_skill (user_id, skill_id),
    INDEX idx_user_skills_user (user_id),
    INDEX idx_user_skills_skill (skill_id),
    INDEX idx_user_skills_expires (expires_at),
    CONSTRAINT fk_user_skills_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_skills_skill
        FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART C: seed a minimal starter catalog per service line
-- These are deliberately broad — managers will refine them per shop. INSERT
-- IGNORE keeps re-runs safe.
-- -----------------------------------------------------------------------------
SET @sl_auto       := (SELECT id FROM service_lines WHERE slug = 'auto_repair'         LIMIT 1);
SET @sl_building   := (SELECT id FROM service_lines WHERE slug = 'building_repair'     LIMIT 1);
SET @sl_property   := (SELECT id FROM service_lines WHERE slug = 'property_management' LIMIT 1);
SET @sl_equipment  := (SELECT id FROM service_lines WHERE slug = 'equipment_repair'    LIMIT 1);
SET @sl_fleet      := (SELECT id FROM service_lines WHERE slug = 'fleet_management'    LIMIT 1);
SET @sl_it         := (SELECT id FROM service_lines WHERE slug = 'it_support'          LIMIT 1);
SET @sl_security   := (SELECT id FROM service_lines WHERE slug = 'security_systems'    LIMIT 1);
SET @sl_pos        := (SELECT id FROM service_lines WHERE slug = 'pos_support'         LIMIT 1);
SET @sl_cleaning   := (SELECT id FROM service_lines WHERE slug = 'commercial_cleaning' LIMIT 1);

INSERT IGNORE INTO skills (slug, name, description, service_line_id, category, sort_order, is_active) VALUES
    -- Auto repair
    ('auto_diagnostics',        'Auto Diagnostics (OBD-II)',           'Read and interpret OBD-II codes; drivability and emissions diagnostics.', @sl_auto, 'diagnostics',  10, 1),
    ('auto_brakes',             'Brake Service',                       'Pad/rotor replacement, brake fluid flush, ABS bleed.',                  @sl_auto, 'mechanical',   20, 1),
    ('auto_engine',             'Engine Repair',                       'Timing belts, head gaskets, internal engine work.',                     @sl_auto, 'mechanical',   30, 1),
    ('auto_transmission',       'Transmission Service',                'Fluid service, clutch replacement, transmission rebuild.',              @sl_auto, 'mechanical',   40, 1),
    ('auto_electrical',         'Auto Electrical',                     'Vehicle wiring, alternators, starters, battery diagnostics.',           @sl_auto, 'electrical',   50, 1),

    -- Building repair
    ('building_carpentry',      'Carpentry',                           'Framing, drywall, doors, trim work.',                                   @sl_building, 'trades',   10, 1),
    ('building_plumbing',       'Plumbing',                            'Fixture replacement, leak repair, drain clearing, supply line.',         @sl_building, 'trades',   20, 1),
    ('building_electrical',     'Electrical (Building)',               'Receptacles, switches, fixture installs (low-voltage and 120V).',       @sl_building, 'trades',   30, 1),
    ('building_hvac',           'HVAC',                                'Furnace, AC, mini-split install and repair; refrigerant cert required.', @sl_building, 'trades',   40, 1),
    ('building_painting',       'Painting',                            'Interior/exterior painting, drywall patch + texture.',                  @sl_building, 'trades',   50, 1),

    -- Property management
    ('property_inspection',     'Property Inspection',                 'Walkthrough inspections, move-in/move-out condition reports.',          @sl_property, 'operations', 10, 1),
    ('property_tenant_relations','Tenant Relations',                   'Tenant complaint handling, lease enforcement, communication.',          @sl_property, 'operations', 20, 1),

    -- Equipment repair
    ('equipment_industrial',    'Industrial Equipment',                'CNC, conveyors, presses; PLC familiarity helpful.',                     @sl_equipment, 'mechanical', 10, 1),
    ('equipment_kitchen',       'Commercial Kitchen Equipment',        'Ovens, fryers, refrigeration, dishwashers.',                            @sl_equipment, 'mechanical', 20, 1),
    ('equipment_medical',       'Medical Equipment',                   'Imaging, lab, and patient-facing devices; calibration training required.', @sl_equipment, 'specialty',  30, 1),

    -- Fleet management
    ('fleet_dot_inspection',    'DOT Inspection',                      'Federal Motor Carrier annual + pre-trip inspections.',                  @sl_fleet, 'compliance', 10, 1),
    ('fleet_telematics',        'Fleet Telematics',                    'GPS tracker install, ELD setup, geofencing config.',                    @sl_fleet, 'technical',  20, 1),

    -- IT support
    ('it_helpdesk',             'Helpdesk / Tier-1 Support',           'Ticket triage, password resets, end-user troubleshooting.',             @sl_it, 'support',    10, 1),
    ('it_networking',           'Networking',                          'Switch/router config, VLANs, firewall rules, troubleshoot LAN/WAN.',    @sl_it, 'technical',  20, 1),
    ('it_endpoints',            'Endpoint Management',                 'Workstation imaging, MDM, endpoint security tools.',                    @sl_it, 'technical',  30, 1),
    ('it_servers',              'Server Administration',               'Windows / Linux server install, AD, patching, backup.',                 @sl_it, 'technical',  40, 1),

    -- Security systems
    ('security_cctv',           'CCTV / IP Cameras',                   'Camera install, NVR config, viewing-app provisioning.',                 @sl_security, 'install',  10, 1),
    ('security_access_control', 'Access Control',                      'Card readers, controllers, door strikes, schedules.',                   @sl_security, 'install',  20, 1),
    ('security_alarm',          'Intrusion Alarms',                    'Panel install, sensor placement, monitoring service handoff.',           @sl_security, 'install',  30, 1),

    -- POS support
    ('pos_terminal_install',    'POS Terminal Install',                'Unbox, mount, network, and provision payment terminals.',               @sl_pos, 'install',     10, 1),
    ('pos_kitchen_display',     'Kitchen Display Systems',             'KDS install, ticket routing config, printer chains.',                   @sl_pos, 'install',     20, 1),
    ('pos_payment_certs',       'Payment Processor Certifications',    'Stripe Terminal / Square / Clover device certifications.',              @sl_pos, 'compliance',  30, 1),

    -- Commercial cleaning
    ('cleaning_floor_care',     'Floor Care',                          'Strip and wax, carpet extraction, hard-floor maintenance.',             @sl_cleaning, 'operations', 10, 1),
    ('cleaning_bloodborne',     'Bloodborne Pathogens / Biohazard',    'OSHA-compliant cleanup of biohazard scenes; cert required.',            @sl_cleaning, 'compliance', 20, 1),

    -- Cross-trade (NULL service_line so they apply everywhere)
    ('xt_first_aid',            'First Aid / CPR',                     'Current Red Cross or American Heart Association certification.',        NULL, 'compliance',     10, 1),
    ('xt_osha_10',              'OSHA 10-Hour',                        'OSHA 10-hour general industry or construction.',                        NULL, 'compliance',     20, 1),
    ('xt_customer_service',     'Customer Service',                    'On-site bedside manner, complaint de-escalation, tone.',                NULL, 'soft',           30, 1),
    ('xt_drivers_license',      'Driver''s License',                   'Valid driver''s license; required for any field role.',                 NULL, 'compliance',     40, 1);

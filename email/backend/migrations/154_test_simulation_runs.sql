-- Test simulation: ledger + operational simulation_run_id markers + colleague is_simulation
-- Safe to re-run: duplicate column/index errors are treated as success by MigrationService

CREATE TABLE IF NOT EXISTS flowone_test_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(16) NOT NULL,
    owner_email VARCHAR(255) NOT NULL,
    owner_domain VARCHAR(255) NOT NULL,
    label VARCHAR(255) NOT NULL,
    summary_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_flowone_test_runs_run_id (run_id),
    INDEX idx_flowone_test_runs_owner (owner_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flowone_test_run_entities (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(16) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id BIGINT NULL,
    entity_pk_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_flowone_test_run_entities_run (run_id, entity_type),
    CONSTRAINT fk_flowone_test_run_entities_run
        FOREIGN KEY (run_id) REFERENCES flowone_test_runs(run_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE organization_colleagues ADD COLUMN simulation_run_id VARCHAR(16) NULL;
ALTER TABLE organization_colleagues ADD COLUMN is_simulation TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE organization_colleagues ADD INDEX idx_org_col_sim_run (simulation_run_id);
ALTER TABLE organization_colleagues ADD INDEX idx_org_col_is_sim (is_simulation);

ALTER TABLE webmail_boards ADD COLUMN simulation_run_id VARCHAR(16) NULL;
ALTER TABLE webmail_boards ADD INDEX idx_webmail_boards_sim_run (simulation_run_id);

ALTER TABLE webmail_board_cards ADD COLUMN simulation_run_id VARCHAR(16) NULL;
ALTER TABLE webmail_board_cards ADD INDEX idx_webmail_board_cards_sim_run (simulation_run_id);

ALTER TABLE projecthub_work_sessions ADD COLUMN simulation_run_id VARCHAR(16) NULL;
ALTER TABLE projecthub_work_sessions ADD INDEX idx_ph_ws_sim_run (simulation_run_id);

ALTER TABLE projecthub_card_assignees ADD COLUMN simulation_run_id VARCHAR(16) NULL;
ALTER TABLE projecthub_card_assignees ADD INDEX idx_ph_ca_sim_run (simulation_run_id);

ALTER TABLE projecthub_spaces ADD COLUMN simulation_run_id VARCHAR(16) NULL;
ALTER TABLE projecthub_spaces ADD INDEX idx_ph_spaces_sim_run (simulation_run_id);

ALTER TABLE projecthub_folder_boards ADD COLUMN simulation_run_id VARCHAR(16) NULL;
ALTER TABLE projecthub_folder_boards ADD INDEX idx_ph_fb_sim_run (simulation_run_id);

ALTER TABLE webmail_card_activity ADD COLUMN simulation_run_id VARCHAR(16) NULL;
ALTER TABLE webmail_card_activity ADD INDEX idx_webmail_card_act_sim_run (simulation_run_id);

ALTER TABLE activity_log ADD COLUMN simulation_run_id VARCHAR(16) NULL;
ALTER TABLE activity_log ADD INDEX idx_activity_log_sim_run (simulation_run_id);

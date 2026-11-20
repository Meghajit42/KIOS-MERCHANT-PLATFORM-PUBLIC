-- Persist configured printer routing and immutable print delivery records.
-- Print data is generated from order/task snapshots; no credential is stored here.

CREATE TABLE printers (
    printer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outlet_id BIGINT UNSIGNED NOT NULL,
    printer_code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    role VARCHAR(32) NOT NULL,
    adapter_type VARCHAR(32) NOT NULL,
    configuration_ref VARCHAR(255) NULL,
    paper_width_mm SMALLINT UNSIGNED NOT NULL DEFAULT 80,
    cut_after_print TINYINT(1) NOT NULL DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (printer_id),
    UNIQUE KEY uq_printers_outlet_code (outlet_id, printer_code),
    UNIQUE KEY uq_printers_outlet_id (outlet_id, printer_id),
    KEY ix_printers_outlet_role_enabled (outlet_id, role, enabled),
    CONSTRAINT chk_printers_role CHECK (role IN ('cashier_receipt', 'station_kot')),
    CONSTRAINT chk_printers_adapter CHECK (adapter_type IN ('virtual', 'windows_spooler')),
    CONSTRAINT chk_printers_width CHECK (paper_width_mm IN (58, 80)),
    CONSTRAINT fk_printers_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (outlet_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many stations may route to one device. Each station has at most one active route.
CREATE TABLE printer_station_routes (
    outlet_id BIGINT UNSIGNED NOT NULL,
    station_id BIGINT UNSIGNED NOT NULL,
    printer_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (outlet_id, station_id),
    KEY ix_printer_station_routes_printer (outlet_id, printer_id),
    CONSTRAINT fk_printer_routes_station FOREIGN KEY (outlet_id, station_id)
        REFERENCES preparation_stations (outlet_id, station_id) ON DELETE RESTRICT,
    CONSTRAINT fk_printer_routes_printer FOREIGN KEY (outlet_id, printer_id)
        REFERENCES printers (outlet_id, printer_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE print_jobs (
    print_job_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outlet_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    preparation_task_id BIGINT UNSIGNED NULL,
    station_id BIGINT UNSIGNED NULL,
    printer_id BIGINT UNSIGNED NULL,
    job_type VARCHAR(32) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'queued',
    idempotency_key VARCHAR(190) NOT NULL,
    reprint_of_job_id BIGINT UNSIGNED NULL,
    requested_by VARCHAR(80) NULL,
    reprint_reason VARCHAR(240) NULL,
    token_snapshot VARCHAR(24) NOT NULL,
    station_code_snapshot VARCHAR(40) NULL,
    station_name_snapshot VARCHAR(120) NULL,
    printer_name_snapshot VARCHAR(160) NULL,
    adapter_type_snapshot VARCHAR(32) NULL,
    configuration_ref_snapshot VARCHAR(255) NULL,
    paper_width_mm SMALLINT UNSIGNED NOT NULL DEFAULT 80,
    cut_after_print TINYINT(1) NOT NULL DEFAULT 1,
    payload_text MEDIUMTEXT NOT NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error_category VARCHAR(64) NULL,
    last_error_message VARCHAR(180) NULL,
    printed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (print_job_id),
    UNIQUE KEY uq_print_jobs_idempotency (idempotency_key),
    UNIQUE KEY uq_print_jobs_outlet_id (outlet_id, print_job_id),
    KEY ix_print_jobs_outlet_status_time (outlet_id, status, created_at),
    KEY ix_print_jobs_outlet_order (outlet_id, order_id),
    KEY ix_print_jobs_outlet_task (outlet_id, order_id, preparation_task_id),
    CONSTRAINT chk_print_jobs_type CHECK (job_type IN ('CASHIER_RECEIPT', 'STATION_KOT')),
    CONSTRAINT chk_print_jobs_status CHECK (status IN ('queued', 'printing', 'printed', 'retry_pending', 'failed', 'cancelled')),
    CONSTRAINT chk_print_jobs_width CHECK (paper_width_mm IN (58, 80)),
    CONSTRAINT chk_print_jobs_adapter CHECK (adapter_type_snapshot IS NULL OR adapter_type_snapshot IN ('virtual', 'windows_spooler')),
    CONSTRAINT chk_print_jobs_error CHECK (last_error_category IS NULL OR last_error_category IN ('printer_offline', 'printer_not_found', 'no_printer_configured', 'spooler_error', 'write_failed', 'render_failed', 'timeout', 'unknown')),
    CONSTRAINT fk_print_jobs_order FOREIGN KEY (outlet_id, order_id)
        REFERENCES orders (outlet_id, order_id) ON DELETE RESTRICT,
    CONSTRAINT fk_print_jobs_task FOREIGN KEY (outlet_id, order_id, preparation_task_id)
        REFERENCES preparation_tasks (outlet_id, order_id, preparation_task_id) ON DELETE RESTRICT,
    CONSTRAINT fk_print_jobs_station FOREIGN KEY (outlet_id, station_id)
        REFERENCES preparation_stations (outlet_id, station_id) ON DELETE RESTRICT,
    CONSTRAINT fk_print_jobs_printer FOREIGN KEY (outlet_id, printer_id)
        REFERENCES printers (outlet_id, printer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_print_jobs_reprint_source FOREIGN KEY (outlet_id, reprint_of_job_id)
        REFERENCES print_jobs (outlet_id, print_job_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE print_job_attempts (
    print_job_attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outlet_id BIGINT UNSIGNED NOT NULL,
    print_job_id BIGINT UNSIGNED NOT NULL,
    attempt_number SMALLINT UNSIGNED NOT NULL,
    action VARCHAR(16) NOT NULL,
    actor VARCHAR(80) NULL,
    outcome VARCHAR(16) NULL,
    error_category VARCHAR(64) NULL,
    error_message VARCHAR(180) NULL,
    spool_reference VARCHAR(255) NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (print_job_attempt_id),
    UNIQUE KEY uq_print_job_attempt_number (outlet_id, print_job_id, attempt_number),
    KEY ix_print_job_attempts_outlet_time (outlet_id, started_at),
    CONSTRAINT chk_print_job_attempt_action CHECK (action IN ('PRINT', 'RETRY', 'REPRINT')),
    CONSTRAINT chk_print_job_attempt_outcome CHECK (outcome IS NULL OR outcome IN ('printed', 'failed')),
    CONSTRAINT chk_print_job_attempt_error CHECK (error_category IS NULL OR error_category IN ('printer_offline', 'printer_not_found', 'no_printer_configured', 'spooler_error', 'write_failed', 'render_failed', 'timeout', 'unknown')),
    CONSTRAINT fk_print_job_attempt_job FOREIGN KEY (outlet_id, print_job_id)
        REFERENCES print_jobs (outlet_id, print_job_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

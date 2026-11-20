-- The gateway is disabled by default and this migration creates no identities or routes.
CREATE TABLE IF NOT EXISTS print_gateways (
    gateway_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outlet_id BIGINT UNSIGNED NOT NULL,
    secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (gateway_id),
    UNIQUE KEY uq_print_gateway_outlet (outlet_id, gateway_id),
    CONSTRAINT fk_print_gateway_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (outlet_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS print_gateway_destinations (
    outlet_id BIGINT UNSIGNED NOT NULL,
    gateway_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    printer_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (outlet_id, gateway_id, printer_id),
    CONSTRAINT fk_gateway_destination_identity FOREIGN KEY (outlet_id, gateway_id)
        REFERENCES print_gateways (outlet_id, gateway_id),
    CONSTRAINT fk_gateway_destination_printer FOREIGN KEY (outlet_id, printer_id)
        REFERENCES printers (outlet_id, printer_id)
) ENGINE=InnoDB;

ALTER TABLE printers
    DROP CONSTRAINT chk_printers_adapter,
    ADD CONSTRAINT chk_printers_adapter CHECK (adapter_type IN ('virtual', 'windows_spooler', 'gateway'));

ALTER TABLE print_jobs
    DROP CONSTRAINT chk_print_jobs_adapter,
    ADD CONSTRAINT chk_print_jobs_adapter CHECK (adapter_type_snapshot IS NULL OR adapter_type_snapshot IN ('virtual', 'windows_spooler', 'gateway')),
    DROP CONSTRAINT chk_print_jobs_status,
    ADD CONSTRAINT chk_print_jobs_status CHECK (status IN (
        'queued', 'printing', 'printed', 'retry_pending', 'failed', 'cancelled',
        'claimed', 'submitted_unconfirmed', 'needs_review', 'reprint_requested'
    )),
    ADD KEY IF NOT EXISTS ix_print_gateway_queue (outlet_id, adapter_type_snapshot, status, print_job_id);

ALTER TABLE print_job_attempts
    ADD COLUMN IF NOT EXISTS gateway_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN IF NOT EXISTS claim_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN IF NOT EXISTS claim_expires_at DATETIME NULL,
    DROP CONSTRAINT chk_print_job_attempt_outcome,
    ADD CONSTRAINT chk_print_job_attempt_outcome CHECK (outcome IS NULL OR outcome IN ('printed', 'failed', 'submitted', 'needs_review'));

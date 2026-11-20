-- A checkout intent is committed on the order row in the same transaction.
-- Nullable hashes preserve pre-existing and non-web orders; the unique key allows many NULL values.
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS checkout_idempotency_key_hash BINARY(32) NULL,
    ADD COLUMN IF NOT EXISTS checkout_session_binding_hash BINARY(32) NULL,
    ADD COLUMN IF NOT EXISTS checkout_cart_fingerprint BINARY(32) NULL,
    ADD UNIQUE KEY uq_orders_checkout_idempotency_hash (checkout_idempotency_key_hash),
    ADD CONSTRAINT chk_orders_checkout_intent_bundle CHECK (
        (checkout_idempotency_key_hash IS NULL AND checkout_session_binding_hash IS NULL AND checkout_cart_fingerprint IS NULL)
        OR
        (checkout_idempotency_key_hash IS NOT NULL AND checkout_session_binding_hash IS NOT NULL AND checkout_cart_fingerprint IS NOT NULL)
    );

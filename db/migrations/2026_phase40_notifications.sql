-- Phase 40: "notify me about pending memory approvals" (Account Settings).
-- Run this once via phpMyAdmin against the live database before deploying
-- this phase's code -- db/schema.sql has already been updated to match,
-- so a FRESH install never needs this file, only the already-live one.
--
-- notify_email_enc is app-layer encrypted (libsodium crypto_secretbox --
-- see includes/crypto.php), never a plaintext email, so a database-only
-- read (a backup, a compromised DB credential without the separate
-- above-webroot secrets file) never recovers the address. Sized for a
-- nonce (24 bytes) + MAC (16 bytes) + a generously long email address.
ALTER TABLE users
  ADD COLUMN notify_email_enc    VARBINARY(400) NULL,
  ADD COLUMN notify_pending_tags TINYINT(1) NOT NULL DEFAULT 0;

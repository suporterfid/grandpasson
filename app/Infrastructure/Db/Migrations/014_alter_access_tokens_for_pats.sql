-- Allow user-issued PATs alongside service-client access tokens (R10).
-- PATs: kind='pat', client_id NULL, subject_user_id required, optional label.

ALTER TABLE access_tokens DROP FOREIGN KEY fk_access_tokens_client;
ALTER TABLE access_tokens MODIFY client_id VARCHAR(100) NULL;
ALTER TABLE access_tokens
  ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'access' AFTER token_hash,
  ADD COLUMN label VARCHAR(255) NULL AFTER kind;
ALTER TABLE access_tokens
  ADD KEY idx_access_tokens_kind (kind),
  ADD KEY idx_access_tokens_subject (subject_user_id);
ALTER TABLE access_tokens
  ADD CONSTRAINT fk_access_tokens_client FOREIGN KEY (client_id) REFERENCES service_clients(client_id) ON DELETE CASCADE;

-- R11: bind RP PKCE to broker auth codes; track oauth_client on user access tokens.

ALTER TABLE auth_codes
  ADD COLUMN code_challenge VARCHAR(128) NULL AFTER redirect_uri,
  ADD COLUMN code_challenge_method VARCHAR(10) NULL AFTER code_challenge;

ALTER TABLE access_tokens
  ADD COLUMN oauth_client_id VARCHAR(100) NULL AFTER client_id,
  ADD KEY idx_access_tokens_oauth_client (oauth_client_id);

CREATE TABLE IF NOT EXISTS installation_codes (
  code_hash TEXT PRIMARY KEY,
  label TEXT,
  created_at TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  consumed_at TEXT,
  metadata_json TEXT NOT NULL DEFAULT '{}'
);

CREATE TABLE IF NOT EXISTS sites (
  site_id TEXT PRIMARY KEY,
  site_url TEXT NOT NULL,
  home_url TEXT NOT NULL,
  admin_email TEXT NOT NULL,
  site_secret_ciphertext TEXT NOT NULL,
  plugin_version TEXT,
  wp_version TEXT,
  default_model TEXT NOT NULL,
  default_reasoning_effort TEXT NOT NULL,
  allowed_models_json TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS auth_sessions (
  auth_session_id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL,
  wp_user_id INTEGER NOT NULL,
  wp_user_email TEXT NOT NULL,
  wp_user_display_name TEXT NOT NULL,
  local_state TEXT NOT NULL,
  return_url TEXT NOT NULL,
  runtime_status TEXT NOT NULL,
  runtime_error TEXT,
  device_verification_url TEXT,
  device_user_code TEXT,
  broker_code_hash TEXT,
  connection_id TEXT,
  expires_at TEXT NOT NULL,
  completed_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_auth_sessions_site_user
  ON auth_sessions (site_id, wp_user_id, local_state);

CREATE TABLE IF NOT EXISTS user_connections (
  connection_id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL,
  wp_user_id INTEGER NOT NULL,
  status TEXT NOT NULL,
  broker_user_id TEXT,
  account_email TEXT,
  plan_type TEXT,
  auth_mode TEXT,
  default_model TEXT,
  allowed_models_json TEXT NOT NULL DEFAULT '[]',
  rate_limits_json TEXT NOT NULL DEFAULT '{}',
  session_expires_at TEXT,
  runtime_payload_json TEXT NOT NULL DEFAULT '{}',
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  UNIQUE (site_id, wp_user_id)
);

CREATE INDEX IF NOT EXISTS idx_user_connections_site_user
  ON user_connections (site_id, wp_user_id);

CREATE TABLE IF NOT EXISTS secrets (
    token TEXT PRIMARY KEY,
    ciphertext BLOB NOT NULL,
    iv BLOB NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_secrets_expires ON secrets(expires_at);

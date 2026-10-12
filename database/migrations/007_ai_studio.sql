CREATE TABLE ai_drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id),
    campaign_id INTEGER REFERENCES campaigns(id),
    owner_id INTEGER NOT NULL REFERENCES users(id),
    title TEXT NOT NULL,
    brief TEXT NOT NULL,
    audience TEXT NOT NULL,
    tone TEXT NOT NULL,
    facts TEXT NOT NULL DEFAULT '',
    platforms TEXT NOT NULL,
    media_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'brief' CHECK (status IN ('brief', 'queued', 'processing', 'generated', 'failed')),
    model TEXT,
    prompt_version TEXT NOT NULL DEFAULT 'studio-v1',
    result_json TEXT,
    last_error TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    lease_token TEXT,
    lock_until INTEGER,
    version INTEGER NOT NULL DEFAULT 1,
    creation_key TEXT NOT NULL UNIQUE,
    content_id INTEGER UNIQUE REFERENCES content_items(id),
    consent_at INTEGER,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX ai_drafts_queue ON ai_drafts(status, id);
CREATE INDEX ai_drafts_company ON ai_drafts(company_id, updated_at DESC, id DESC);
CREATE TRIGGER ai_campaign_company_insert BEFORE INSERT ON ai_drafts
WHEN NEW.campaign_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM campaigns WHERE id = NEW.campaign_id AND company_id = NEW.company_id
)
BEGIN
    SELECT RAISE(ABORT, 'AI campaign must belong to company');
END;
CREATE TABLE content_ai_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_item_id INTEGER NOT NULL REFERENCES content_items(id),
    platform TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    model TEXT NOT NULL,
    prompt_version TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    UNIQUE(content_item_id, platform)
);

CREATE TABLE ai_images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    draft_id INTEGER NOT NULL REFERENCES ai_drafts(id),
    owner_id INTEGER NOT NULL REFERENCES users(id),
    platform TEXT NOT NULL,
    prompt TEXT NOT NULL,
    style TEXT NOT NULL,
    aspect_ratio TEXT NOT NULL,
    model TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('processing', 'generated', 'failed')),
    creation_key TEXT NOT NULL UNIQUE,
    filename TEXT,
    mime_type TEXT,
    width INTEGER,
    height INTEGER,
    last_error TEXT,
    content_id INTEGER REFERENCES content_items(id),
    consent_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX ai_images_draft ON ai_images(draft_id, id DESC);

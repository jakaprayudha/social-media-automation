CREATE TABLE companies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    timezone TEXT NOT NULL DEFAULT 'Asia/Jakarta',
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

INSERT INTO companies (name, slug, created_at, updated_at) VALUES
    ('Signal Prima Solusi', 'signal-prima-solusi', unixepoch(), unixepoch()),
    ('Netindo Persada Nusantara', 'netindo-persada-nusantara', unixepoch(), unixepoch()),
    ('Mega Data Link', 'mega-data-link', unixepoch(), unixepoch());

CREATE TABLE content_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id),
    owner_id INTEGER NOT NULL REFERENCES users(id),
    title TEXT NOT NULL,
    format TEXT NOT NULL DEFAULT 'unspecified',
    brief TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'idea' CHECK (status IN (
        'idea', 'brief', 'ai_draft', 'pending_review', 'needs_revision',
        'approved', 'scheduled', 'processing', 'published', 'failed', 'cancelled'
    )),
    scheduled_at INTEGER,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX content_items_company_status ON content_items(company_id, status);
CREATE INDEX content_items_updated ON content_items(updated_at DESC, id DESC);

CREATE TABLE social_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id),
    platform TEXT NOT NULL CHECK (platform IN ('tiktok', 'instagram', 'facebook')),
    account_id TEXT NOT NULL,
    display_name TEXT NOT NULL,
    connection_status TEXT NOT NULL DEFAULT 'disconnected'
        CHECK (connection_status IN ('connected', 'disconnected', 'needs_authorization', 'error')),
    scopes TEXT NOT NULL DEFAULT '[]',
    token_reference TEXT,
    last_checked_at INTEGER,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    UNIQUE(platform, account_id)
);
CREATE INDEX social_accounts_company ON social_accounts(company_id, platform, connection_status);

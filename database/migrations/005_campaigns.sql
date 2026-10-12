CREATE TABLE campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id),
    owner_id INTEGER NOT NULL REFERENCES users(id),
    name TEXT NOT NULL,
    objective TEXT NOT NULL DEFAULT '',
    theme TEXT NOT NULL DEFAULT '',
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL CHECK (end_date >= start_date),
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'completed', 'archived')),
    version INTEGER NOT NULL DEFAULT 1,
    creation_key TEXT NOT NULL UNIQUE,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX campaigns_company_status ON campaigns(company_id, status);
ALTER TABLE content_items ADD COLUMN campaign_id INTEGER REFERENCES campaigns(id);
CREATE INDEX content_items_campaign ON content_items(campaign_id, deleted_at);

CREATE TRIGGER content_campaign_company_insert BEFORE INSERT ON content_items
WHEN NEW.campaign_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM campaigns WHERE id = NEW.campaign_id AND company_id = NEW.company_id
)
BEGIN
    SELECT RAISE(ABORT, 'Campaign must belong to content company');
END;
CREATE TRIGGER content_campaign_company_update BEFORE UPDATE OF campaign_id, company_id ON content_items
WHEN NEW.campaign_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM campaigns WHERE id = NEW.campaign_id AND company_id = NEW.company_id
)
BEGIN
    SELECT RAISE(ABORT, 'Campaign must belong to content company');
END;
CREATE TRIGGER campaign_company_immutable BEFORE UPDATE OF company_id ON campaigns
WHEN NEW.company_id != OLD.company_id
BEGIN
    SELECT RAISE(ABORT, 'Campaign company cannot change');
END;

ALTER TABLE content_items ADD COLUMN version INTEGER NOT NULL DEFAULT 1;
CREATE TRIGGER content_version_update AFTER UPDATE ON content_items
WHEN NEW.version = OLD.version
BEGIN
    UPDATE content_items SET version = OLD.version + 1 WHERE id = NEW.id;
END;

ALTER TABLE content_items ADD COLUMN deleted_at INTEGER;
ALTER TABLE content_items ADD COLUMN deleted_by INTEGER REFERENCES users(id);

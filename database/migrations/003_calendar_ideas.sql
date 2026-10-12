CREATE TABLE observances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category TEXT NOT NULL CHECK (category IN ('national', 'international')),
    month INTEGER NOT NULL CHECK (month BETWEEN 1 AND 12),
    day INTEGER NOT NULL CHECK (day BETWEEN 1 AND 31),
    first_year INTEGER NOT NULL,
    source_url TEXT NOT NULL,
    verified_on TEXT NOT NULL
);

INSERT INTO observances (slug, name, category, month, day, first_year, source_url, verified_on) VALUES
    ('pendidikan-nasional', 'Hari Pendidikan Nasional', 'national', 5, 2, 1959, 'https://id.wikipedia.org/wiki/Hari_Pendidikan_Nasional', '2026-10-12'),
    ('kemerdekaan-ri', 'Hari Kemerdekaan Republik Indonesia', 'national', 8, 17, 1945, 'https://id.wikipedia.org/wiki/Hari_Kemerdekaan_Republik_Indonesia', '2026-10-12'),
    ('batik-nasional', 'Hari Batik Nasional', 'national', 10, 2, 2009, 'https://id.wikipedia.org/wiki/Hari_Batik_Nasional', '2026-10-12'),
    ('sumpah-pemuda', 'Hari Sumpah Pemuda', 'national', 10, 28, 1959, 'https://id.wikipedia.org/wiki/Sumpah_Pemuda', '2026-10-12'),
    ('perempuan-internasional', 'Hari Perempuan Internasional', 'international', 3, 8, 1977, 'https://www.un.org/en/observances/womens-day', '2026-10-12'),
    ('lingkungan-hidup', 'Hari Lingkungan Hidup Sedunia', 'international', 6, 5, 1973, 'https://www.un.org/en/observances/environment-day', '2026-10-12'),
    ('keterampilan-pemuda', 'Hari Keterampilan Pemuda Sedunia', 'international', 7, 15, 2015, 'https://www.un.org/en/observances/world-youth-skills-day', '2026-10-12'),
    ('perdamaian-internasional', 'Hari Perdamaian Internasional', 'international', 9, 21, 2002, 'https://www.un.org/en/observances/international-day-peace', '2026-10-12');

ALTER TABLE content_items ADD COLUMN planned_at INTEGER;
ALTER TABLE content_items ADD COLUMN observance_id INTEGER REFERENCES observances(id);
ALTER TABLE content_items ADD COLUMN observance_year INTEGER CHECK (observance_year BETWEEN 2000 AND 2099);
ALTER TABLE content_items ADD COLUMN creation_key TEXT;
CREATE UNIQUE INDEX content_observance_once ON content_items(company_id, observance_id, observance_year);
CREATE UNIQUE INDEX content_creation_once ON content_items(creation_key) WHERE creation_key IS NOT NULL;
CREATE INDEX content_calendar_date ON content_items(COALESCE(scheduled_at, planned_at));

CREATE TABLE calendar_automation (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    enabled INTEGER NOT NULL CHECK (enabled IN (0, 1)),
    owner_id INTEGER NOT NULL REFERENCES users(id),
    last_run_at INTEGER,
    updated_at INTEGER NOT NULL
);

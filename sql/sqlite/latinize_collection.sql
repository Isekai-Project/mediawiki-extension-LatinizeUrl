CREATE TABLE /*_*/latinize_collection (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL UNIQUE,
    sort_key TEXT NOT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/latinize_collection_title ON /*_*/latinize_collection (title);
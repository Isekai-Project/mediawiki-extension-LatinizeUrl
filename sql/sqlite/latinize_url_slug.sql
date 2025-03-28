CREATE TABLE /*_*/latinize_url_slug (
    id INTEGER NOT NULL PRIMARY KEY,
    title TEXT NOT NULL UNIQUE, -- Page title
    url_slug TEXT NOT NULL, -- Custom URL Slug
    is_custom INTEGER NOT NULL DEFAULT 0, -- Is custom URL
    latinized_words TEXT -- Latinized words for title
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/latinize_url_slug_title ON /*_*/latinize_url_slug (title);
CREATE INDEX /*i*/latinize_url_slug_url_slug ON /*_*/latinize_url_slug (url_slug);
CREATE TABLE /*_*/latinize_url_slug (
    `id` INT NOT NULL PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Page title',
    `url_slug` VARCHAR(255) NOT NULL COMMENT 'Custom URL Slug',
    `is_custom` TINYINT NOT NULL DEFAULT 0 COMMENT 'Is custom URL',
    `latinized_words` TEXT COMMENT 'Latinized words for title'
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/latinize_url_slug_title ON /*_*/latinize_url_slug (title);
CREATE INDEX /*i*/latinize_url_slug_url_slug ON /*_*/latinize_url_slug (url_slug);
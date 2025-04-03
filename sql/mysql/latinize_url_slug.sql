CREATE TABLE /*_*/latinize_url_slug (
    `id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT COMMENT 'Primary key',
    `title` VARCHAR(255) NOT NULL COMMENT 'Page title',
    `url_slug` VARCHAR(1024) NOT NULL COMMENT 'Custom URL Slug',
    `is_custom` TINYINT NOT NULL DEFAULT 0 COMMENT 'Is custom URL',
    `latinized_words` TEXT COMMENT 'Latinized words for title'
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/latinize_url_slug_title ON /*_*/latinize_url_slug (title);
CREATE INDEX /*i*/latinize_url_slug_url_slug ON /*_*/latinize_url_slug (url_slug);
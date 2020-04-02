CREATE TABLE /*_*/url_slug (
    `id` INT NOT NULL PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL UNIQUE,
    `slug` VARCHAR(255) NOT NULL,
    `url` VARCHAR(255) NOT NULL UNIQUE,
    `is_custom` TINYINT NOT NULL DEFAULT 0,
    `show_id` TINYINT NOT NULL DEFAULT 0,
    `latinize` TEXT
) /*$wgDBTableOptions*/;
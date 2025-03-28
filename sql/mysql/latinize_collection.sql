CREATE TABLE /*_*/latinize_collection (
    `id` INT NOT NULL PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL UNIQUE,
    `sort_key` VARCHAR(10) NOT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/latinize_collection_title ON /*_*/latinize_collection (title);
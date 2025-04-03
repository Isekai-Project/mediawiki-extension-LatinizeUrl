CREATE TABLE /*_*/latinize_collection (
    `id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL UNIQUE,
    `sort_key` TEXT NOT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/latinize_collection_title ON /*_*/latinize_collection (title);
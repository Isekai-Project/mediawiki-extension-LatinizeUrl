<?php

namespace LatinizeUrl\Service;

use MediaWiki\Deferred\AutoCommitUpdate;
use MediaWiki\Deferred\DeferredUpdates;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

class SortKeyStore {
    public function __construct(private IDatabase $primary, private IReadableDatabase $replica) {
    }

    public function get(string $text) {
        $row = $this->replica->selectRow('latinize_collection', ['sort_key'],
            ['title' => $text], __METHOD__);
        return $row ? $row->sort_key : false;
    }

    public function set(string $text, string $key): void {
        // Collation reads must not introduce writes into the caller's read transaction.
        DeferredUpdates::addUpdate(new AutoCommitUpdate($this->primary, __METHOD__,
            static function ($db) use ($text, $key) {
                $db->replace('latinize_collection', ['title'],
                    ['title' => $text, 'sort_key' => $key], __METHOD__);
            }));
    }
}

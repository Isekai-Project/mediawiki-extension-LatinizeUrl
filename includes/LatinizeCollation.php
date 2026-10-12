<?php

namespace LatinizeUrl;

use Collation;
use LatinizeUrl\Service\SortKeyStore;
use LatinizeUrl\Service\TitleLatinizer;
use Wikimedia\ObjectCache\WANObjectCache;

class LatinizeCollation extends Collation {
    private $cache;

    public function __construct(private SortKeyStore $sortKeys, private TitleLatinizer $latinizer,
        WANObjectCache $cache) {
        $this->cache = $cache;
    }

    private function getLatinize($string) {
        return $this->latinizer->forSortKey($string);
    }

    public function getSortKey($string) {
        if (defined('MW_UPDATER')) {
            return $string;
        }

        return $this->cache->getWithSetCallback(
            $this->cache->makeKey('latinizeSortKey', $string),
            $this->cache::TTL_MINUTE * 10,
            function () use ($string) {
                $sortKey = $this->sortKeys->get($string);
                if ($sortKey) {
                    return ucfirst($sortKey);
                } else {
                    $sortKey = $this->getLatinize($string);

                    if ($sortKey) {
                        $this->sortKeys->set($string, $sortKey);
                        return $sortKey;
                    }

                    return ucfirst($string);
                }
            }
        );
    }

    public function getFirstLetter($string) {
        if (defined('MW_UPDATER')) {
            return mb_substr(0, 1, $string, 'UTF-8');
        }

        $sortKey = $this->getSortKey($string);

        return strtoupper(mb_substr($sortKey, 0, 1, 'UTF-8'));
    }
}

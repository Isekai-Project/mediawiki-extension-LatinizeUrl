<?php

namespace LatinizeUrl;

use Collation;
use MediaWiki\MediaWikiServices;

class LatinizeCollation extends Collation {
    private $cache;

    public function __construct() {
        $this->cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
    }

    private function getLatinize($string) {
        $convertor = Utils::getConvertor();
        $latinize = $convertor->parse($string);
        return Utils::wordListToUrl($latinize);
    }

    public function getSortKey($string) {
        if (defined('MW_UPDATER')) {
            return $string;
        }

        return $this->cache->getWithSetCallback(
            $this->cache->makeKey('latinizeSortKey', $string),
            $this->cache::TTL_MINUTE * 10,
            function () use ($string) {
                $sortKey = Utils::getLatinizeSortKey($string);
                if ($sortKey) {
                    return ucfirst($sortKey);
                } else {
                    $sortKey = $this->getLatinize($string);

                    if ($sortKey) {
                        Utils::setLatinizeSortKey($string, $sortKey);
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

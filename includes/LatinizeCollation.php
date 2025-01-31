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
        return $this->cache->getWithSetCallback(
            $this->cache->makeKey('latinizeConvert', $string),
            $this->cache::TTL_MINUTE * 10,
            function () use ($string) {
                $convertor = Utils::getConvertor();
                $latinize = $convertor->parse($string);
                return Utils::wordListToUrl($latinize);
            }
        );
    }

    public function getSortKey($string) {
        if (defined('MW_UPDATER')) {
            return $string;
        }

        $slug = Utils::getSlugByTitle($string);
        if ($slug) {
            return ucfirst($slug);
        } else {
            $latinize = $this->getLatinize($string);

            if ($latinize) {
                return $latinize;
            }

            return ucfirst($slug);
        }
    }

    public function getFirstLetter($string) {
        if (defined('MW_UPDATER')) {
            return mb_substr(0, 1, $string, 'UTF-8');
        }

        $slug = Utils::getSlugByTitle($string);
        if ($slug) {
            return strtoupper($slug[0]);
        } else {
            $latinize = $this->getLatinize($string);

            if ($latinize) {
                return strtoupper(mb_substr($latinize, 0, 1, 'UTF-8'));
            }
        }

        return mb_substr(0, 1, $string, 'UTF-8');
    }
}

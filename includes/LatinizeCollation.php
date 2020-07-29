<?php
namespace LatinizeUrl;

use Collation;
use MediaWiki\MediaWikiServices;

class LatinizeCollation extends Collation {
    private $cache = null;

    public function __construct(){
        $this->cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
    }

    private function getLatinize($string){
        global $wgLatinizeUrlConfig;

        return $this->cache->getWithSetCallback(
            $this->cache->makeKey('latinizeConvert', $string),
            $this->cache::TTL_MINUTE * 10,
            function() use($string, $wgLatinizeUrlConfig){
                $convertor = new Hanzi2Pinyin($wgLatinizeUrlConfig);
                $latinize = $convertor->parse($string);
                $slug = Utils::wordListToUrl($latinize);
                return $slug;
            }
        );
    }

    public function getSortKey($string){
        $slug = Utils::getSlugByTitle($string);
        if($slug){
            return ucfirst($slug);
        } else {
            return $this->getLatinize($string);
        }
    }

    public function getFirstLetter($string){
        $slug = Utils::getSlugByTitle($string);
        if($slug){
            return strtoupper($slug[0]);
        } else {
            return strtoupper(mb_substr($this->getLatinize($string), 0, 1, 'UTF-8'));
        }
    }
}
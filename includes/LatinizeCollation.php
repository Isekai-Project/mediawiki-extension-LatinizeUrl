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
        return $this->cache->getWithSetCallback(
            $this->cache->makeKey('latinizeConvert', $string),
            $this->cache::TTL_MINUTE * 10,
            function() use($string){
                $convertor = Utils::getConvertor();
                $latinize = $convertor->parse($string);
                $slug = Utils::wordListToUrl($latinize);
                return $slug;
            }
        );
    }

    public function getSortKey($string){
        if(defined('MW_UPDATER')){
            return $string;
        }

        $slug = Utils::getSlugByTitle($string);
        if($slug){
            return ucfirst($slug);
        } else {
            return $this->getLatinize($string);
        }
    }

    public function getFirstLetter($string){
        if(defined('MW_UPDATER')){
            return mb_substr(0, 1, $string, 'UTF-8');
        }

        $slug = Utils::getSlugByTitle($string);
        if($slug){
            return strtoupper($slug[0]);
        } else {
            return strtoupper(mb_substr($this->getLatinize($string), 0, 1, 'UTF-8'));
        }
    }
}
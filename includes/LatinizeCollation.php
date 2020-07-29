<?php
namespace LatinizeUrl;

use Collation;

class LatinizeCollation extends Collation {
    public function getSortKey($string){
        $slug = Utils::getSlugByTitle($string);
        if($slug){
            return ucfirst($slug);
        } else {
            return $string;
        }
    }

    public function getFirstLetter($string){
        $slug = Utils::getSlugByTitle($string);
        if($slug){
            return strtoupper($slug[0]);
        } else {
            return mb_substr($string, 0, 1, 'UTF-8');
        }
    }
}
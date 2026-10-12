<?php

namespace LatinizeUrl;

use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

class Utils {
    public static function getTitleInfo($title, $namespace = null) {
        if (is_string($title)) {
            return new BaseTitleInfo($title, $namespace ?? NS_MAIN);
        } elseif ($title instanceof Title ||
            (is_callable([$title, 'getText']) && is_callable([$title, 'getNamespace']))) {
            return new BaseTitleInfo($title->getText(), $namespace ?? $title->getNamespace());
        } else {
            return false;
        }
    }

    public static function hasUserEditedPage(Title $title, User $user) {
        if ($user->isAnon()) return false;
        if (!$title->exists()) return false;

        $wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();

        $wikiPage = $wikiPageFactory->newFromTitle($title);
        if (!$wikiPage) return false;
        $contributors = $wikiPage->getContributors();
        foreach ($contributors as $contributor) {
            if ($contributor->equals($user)) {
                return true;
            }
        }
        return false;
    }

    public static function encodeUriComponent($str) {
        $entities = ['+', '%21', '%2A', '%27', '%28', '%29', '%3B', '%3A', '%40', '%3D', '%2B', '%24', '%2C', '%2F', '%3F', '%25', '%23', '%5B', '%5D'];
        $replacements = ['_', '!', '*', "'", "(", ")", ";", ":", "@", "=", "+", "$", ",", "/", "?", "%", "#", "[", "]"];
        return str_replace($entities, $replacements, implode("/", array_map("urlencode", explode("/", $str))));
    }

    public static function getVersion() {
        return ExtensionRegistry::getInstance()->getAllThings()['LatinizeUrl']['version'];
    }

    public static function wordListToUrl($sentenceList) {
        $strBuilder = [];
        foreach ($sentenceList as $pinyinList) {
            if (is_array($pinyinList)) {
                $segStrBuilder = [];
                foreach ($pinyinList as $pinyinGroup) {
                    if (is_array($pinyinGroup)) {
                        $groupStrBuilder = [];
                        foreach ($pinyinGroup as $pinyin) {
                            $groupStrBuilder[] = ucfirst($pinyin);
                        }
                        $segStrBuilder[] = implode('', $groupStrBuilder);
                    } else {
                        $segStrBuilder[] = $pinyinGroup;
                    }
                }
                $strBuilder[] = implode('-', $segStrBuilder);
            } else {
                $strBuilder[] = $pinyinList;
            }
        }
        $str = implode('-', $strBuilder);
        $str = preg_replace('/-([\x20-\x2f\x3a-\x40\x5b-\x60\x7a-\x7f])-/', '$1', $str);
        $str = str_replace([' ', '&', '?', '!', '#', '%'], '-', $str);
        $str = str_replace(['-)'], [')'], $str);
        $str = preg_replace('/-+/', '-', $str);
        $str = preg_replace('/(^-|-$)/', '', $str);
        return $str;
    }
}

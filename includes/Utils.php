<?php

namespace LatinizeUrl;

use Exception;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Language;
use MediaWiki\MediaWikiServices;
use StubUserLang;
use MediaWiki\Registration\ExtensionRegistry;

class Utils {
    private static $dbr = null;
    private static $dbw = null;
    private static $cache = null;
    private const PAGE_ID_SEPARATOR = '-';
    private const OLD_PAGE_ID_SEPARATORS = ['~'];

    public static function initMasterDb() {
        if (!self::$dbw) {
            self::$dbw =  MediaWikiServices::getInstance()->getDBLoadBalancer()
                ->getMaintenanceConnectionRef(DB_PRIMARY);
        }
    }

    public static function initReplicaDb() {
        if (!self::$dbr) {
            self::$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()
                ->getMaintenanceConnectionRef(DB_REPLICA);
        }
    }

    public static function initCache() {
        if (!self::$cache) {
            self::$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        }
    }

    public static function slugExists($slug, $excludeUrl = null) {
        if ($excludeUrl) {
            self::initReplicaDb();

            $cond = [
                'slug' => $slug,
            ];

            $cond['url'] = ['!', self::$dbr->addQuotes($excludeUrl)];
            $res = self::$dbr->selectField('url_slug', 'COUNT(*)', $cond, __METHOD__);
            return intval($res) > 0;
        } else {
            return self::getTitleTextBySlug($slug) !== false;
        }
    }

    public static function slugUrlExists($url) {
        return self::getTitleTextBySlugUrl($url) !== false;
    }

    public static function titleSlugExists($title) {
        return self::getSlugByTitle($title) !== false;
    }

    public static function getTitleBySlug($slug, $namespace = NS_MAIN) {
        if ($slug instanceof Title) {
            $namespace = $slug->getNamespace();
            $slug = $slug->getText();
        }

        $titleText = self::getTitleTextBySlug($slug);
        if ($titleText) {
            return Title::newFromText($titleText, $namespace);
        } else {
            return false;
        }
    }

    /**
     * 从URL中获取标题
     * @param Title|string $url
     * @param int $namespace
     */
    public static function getTitleBySlugUrl($url, $namespace = NS_MAIN) {
        if ($url instanceof Title) {
            $namespace = $url->getNamespace();
            $url = $url->getText();
        }

        $wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
        // 新版在URL中加了pageId
        $pageIdSeparator = array_merge(self::OLD_PAGE_ID_SEPARATORS, [self::PAGE_ID_SEPARATOR]);
        $pageIdSeparator = implode('|', array_map('preg_quote', $pageIdSeparator));
        if (preg_match('/^(\d+?)(' . $pageIdSeparator . ')/', $url, $matches)) {
            $pageId = intval($matches[1]);

            $wikiPage = $wikiPageFactory->newFromID($pageId);
            if ($wikiPage) {
                return $wikiPage->getTitle();
            }
        }

        // 旧版URL
        $titleText = self::getTitleTextBySlugUrl($url);
        if ($titleText) {
            return Title::newFromText($titleText, $namespace);
        } else {
            return false;
        }
    }

    public static function getTitleTextBySlug($slug) {
        self::initCache();
        self::initReplicaDb();

        return self::$cache->getWithSetCallback(
            self::$cache->makeKey('slug2title', $slug),
            self::$cache::TTL_MINUTE * 10,
            function () use ($slug) {
                $res = self::$dbr->select('url_slug', ['title'], [
                    'slug' => $slug,
                ], __METHOD__, [
                    'LIMIT' => 1,
                ]);
                if ($res->numRows() > 0) {
                    $data = $res->fetchRow();
                    return $data['title'];
                } else {
                    return false;
                }
            }
        );
    }

    public static function getTitleTextBySlugUrl($url) {
        self::initCache();
        self::initReplicaDb();

        return self::$cache->getWithSetCallback(
            self::$cache->makeKey('slugurl2title', $url),
            self::$cache::TTL_MINUTE * 10,
            function () use ($url) {
                $res = self::$dbr->select('url_slug', ['title'], [
                    'url' => $url,
                ], __METHOD__, [
                    'LIMIT' => 1,
                ]);
                if ($res->numRows() > 0) {
                    $data = $res->fetchRow();
                    return $data['title'];
                } else {
                    return false;
                }
            }
        );
    }

    public static function getSlugByTitle($title) {
        if ($title instanceof Title) {
            $title = $title->getText();
        }

        self::initCache();
        self::initReplicaDb();

        return self::$cache->getWithSetCallback(
            self::$cache->makeKey('title2slug', $title),
            self::$cache::TTL_MINUTE * 10,
            function () use ($title) {
                $res = self::$dbr->select('url_slug', ['slug'], [
                    'title' => $title,
                ], __METHOD__, [
                    'LIMIT' => 1,
                ]);
                if ($res->numRows() > 0) {
                    $data = $res->fetchRow();
                    return $data['slug'];
                } else {
                    return false;
                }
            }
        );
    }

    public static function getSlugUrlByTitleWithoutId($title) {
        if ($title instanceof Title) {
            $titleText = $title->getText();
        } else {
            $titleText = $title;
            $title = Title::newFromText($title);
        }

        self::initCache();
        self::initReplicaDb();

        return self::$cache->getWithSetCallback(
            self::$cache->makeKey('title2slugurlorig', $titleText),
            self::$cache::TTL_MINUTE * 10,
            function () use ($title, $titleText) {
                $res = self::$dbr->select('url_slug', ['url'], [
                    'title' => $title,
                ], __METHOD__, [
                    'LIMIT' => 1,
                ]);

                if ($res->numRows() > 0) {
                    $data = $res->fetchRow();

                    $url = $data['url'];

                    return $url;
                } else {
                    return false;
                }
            }
        );
    }

    public static function getSlugUrlByTitle($title) {
        if (is_string($title)) {
            $title = Title::newFromText($title);
        }

        if (!$title) return false;

        self::initCache();

        return self::$cache->getWithSetCallback(
            self::$cache->makeKey('title2slugurl', $title->getText()),
            self::$cache::TTL_MINUTE * 10,
            function () use ($title) {
                $slugUrl = self::getSlugUrlByTitleWithoutId($title);
                if (!$slugUrl) return false;

                $wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();

                if (!$title->isMainPage()) {
                    $wikiPage = $wikiPageFactory->newFromTitle($title);

                    if ($wikiPage) {
                        $pageId = $wikiPage->getId();
                        $slugUrl = $pageId . self::PAGE_ID_SEPARATOR . $slugUrl;
                    }
                }

                return $slugUrl;
            }
        );
    }

    public static function getSlugDataByTitle($title) {
        if ($title instanceof Title) {
            $title = $title->getText();
        }

        self::initCache();
        self::initReplicaDb();

        $res = self::$dbr->select('url_slug', '*', [
            'title' => $title,
        ], __METHOD__, [
            'LIMIT' => 1,
        ]);
        if ($res->numRows() > 0) {
            $data = $res->fetchRow();
            return $data;
        } else {
            return false;
        }
    }

    public static function addTitleSlugMap($title, $slug, $latinize = [], $custom = 0) {
        if (self::titleSlugExists($title)) {
            throw new Exception("Title slug map already exists: " . $title);
        }
        self::initMasterDb();

        $exists = self::slugExists($slug);

        if ($exists) {
            $url = $slug . '-id';
        } else {
            $url = $slug;
        }

        self::$dbw->insert('url_slug', array(
            'title' => $title,
            'slug' => $slug,
            'url' => $url,
            'show_id' => $exists,
            'is_custom' => $custom,
            'latinize' => json_encode($latinize),
        ), __METHOD__);
        $lastId = self::$dbw->insertId();
        if ($exists) {
            $url = $slug . '-' . $lastId;
            self::$dbw->update('url_slug', [
                'url' => $url,
            ], [
                'id' => intval($lastId),
            ], __METHOD__);
        }
        return $url;
    }

    public static function updateTitleSlugMap($title, $slug, $latinize = [], $custom = 0) {
        if (!self::titleSlugExists($title)) {
            throw new Exception("Title slug map not exists: " . $title);
        }
        self::initMasterDb();
        self::initReplicaDb();

        $res = self::$dbr->selectRow('url_slug', ['id', 'slug', 'url', 'show_id'], [
            'title' => $title,
        ], __METHOD__);

        $mapId = intval($res->id);
        $oldSlug = $res->slug;
        $oldUrl = $res->url;

        if ($oldSlug == $slug) return $oldUrl;

        $exists = self::slugExists($slug, $slug);
        if ($exists) {
            $url = $slug . '-' . strval($mapId);
        } else {
            $url = $slug;
        }

        $data = [
            'slug' => $slug,
            'url' => $url,
            'show_id' => $exists ? 1 : 0,
            'is_custom' => $custom,
        ];
        if (!empty($latinize)) {
            $data['latinize'] = json_encode($latinize);
        }

        self::$dbw->update('url_slug', $data, [
            'id' => $mapId,
        ], __METHOD__);

        self::$cache->delete(self::$cache->makeKey('slug2title', $oldSlug));
        self::$cache->delete(self::$cache->makeKey('slugurl2title', $oldUrl));
        self::$cache->delete(self::$cache->makeKey('title2slug', $title));
        self::$cache->delete(self::$cache->makeKey('title2slugurl', $title));
        return $url;
    }

    public static function replaceTitleSlugMap($title, $slug, $latinize = [], $custom = 0) {
        if (self::titleSlugExists($title)) {
            return self::updateTitleSlugMap($title, $slug, $latinize, $custom);
        } else {
            return self::addTitleSlugMap($title, $slug, $latinize, $custom);
        }
    }

    public static function removeTitleSlugMap($title) {
        self::initMasterDb();

        if (self::titleSlugExists($title)) {
            $oldData = self::$dbr->selectRow('url_slug', ['slug', 'url'], [
                'title' => $title,
            ], __METHOD__);

            self::$dbw->delete('url_slug', [
                'title' => $title,
            ]);

            self::$cache->delete(self::$cache->makeKey('slug2title', $oldData->slug));
            self::$cache->delete(self::$cache->makeKey('slugurl2title', $oldData->url));
            self::$cache->delete(self::$cache->makeKey('title2slug', $title));
            self::$cache->delete(self::$cache->makeKey('title2slugurl', $title));
            return true;
        } else {
            return true;
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
        $entities = ['+', '%21', '%2A', '%27', '%28', '%29', '%3B', '%3A', '%40', '%26', '%3D', '%2B', '%24', '%2C', '%2F', '%3F', '%25', '%23', '%5B', '%5D'];
        $replacements = ['_', '!', '*', "'", "(", ")", ";", ":", "@", "&", "=", "+", "$", ",", "/", "?", "%", "#", "[", "]"];
        return str_replace($entities, $replacements, implode("/", array_map("urlencode", explode("/", $str))));
    }

    /**
     * @param Language|StubUserLang|string|null $language - 语言
     * @return BaseConvertor 转换器
     */
    public static function getConvertor($language = null) {
        if ($language == null) {
            $language = MediaWikiServices::getInstance()->getContentLanguage();
        }

        if (is_callable([$language, 'getCode'])) {
            $language = $language->getCode();
        }

        $convertor = null;

        MediaWikiServices::getInstance()->getHookContainer()->run('LatinizeUrlGetConvertor', [
            $language,
            &$convertor,
        ]);

        return $convertor;
    }

    /**
     * @param Title $title - 要转换的标题
     * @param Language|StubUserLang|string|null $language - 语言
     * @return mixed 转换器
     */
    public static function parseTitleToAscii(Title $title, Language $language) {
        try {
            $convertor = self::getConvertor($language);
            if ($title->isSubpage()) {
                //处理子页面，按照页面拆分
                $titlePathList = explode('/', $title->getText());
                $titlePathLen = count($titlePathList);
                $unparsed = $title->getText();
                $baseSlug = false;
                for ($i = $titlePathLen - 2; $i >= 0; $i--) {
                    $titleSubPath = implode('/', array_slice($titlePathList, 0, $i + 1));
                    $baseTitle = Title::newFromText($titleSubPath, $title->getNamespace());
                    $baseSlug = self::getSlugUrlByTitleWithoutId($baseTitle);
                    if ($baseSlug) {
                        $unparsed = implode('/', array_slice($titlePathList, $i + 1));
                        break;
                    }
                }
                $parsed = $convertor->parse($unparsed);
                if ($parsed) {
                    $parsedSlug = self::wordListToUrl($parsed);
                    if ($baseSlug) {
                        return [
                            'slug' => $baseSlug . '/' . $parsedSlug,
                            'latinize' => array_merge([$baseSlug, '/'], $parsed),
                        ];
                    } else {
                        return [
                            'slug' => $parsedSlug,
                            'latinize' => $parsed,
                        ];
                    }
                } else {
                    return false;
                }
            } else {
                $parsed = $convertor->parse($title->getText());
                if ($parsed) {
                    $parsedSlug = self::wordListToUrl($parsed);
                    return [
                        'slug' => $parsedSlug,
                        'latinize' => $parsed,
                    ];
                } else {
                    return false;
                }
            }
        } catch (Exception $ex) {
            wfLogWarning('Cannot parse title to ascii: ' . $ex->getMessage());
            wfLogWarning($ex->getTraceAsString(), E_USER_ERROR);
            return false;
        }
    }

    public static function getVersion() {
        return ExtensionRegistry::getInstance()->getAllThings()['LatinizeUrl']['version'];
    }

    /**
     * @param string[] $sentenceList
     * @return string
     */
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
        return $str;
    }
}

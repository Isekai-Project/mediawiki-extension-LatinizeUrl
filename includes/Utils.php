<?php

namespace LatinizeUrl;

use Exception;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\Language\Language;
use MediaWiki\MediaWikiServices;
use StubUserLang;
use MediaWiki\Registration\ExtensionRegistry;

class BaseTitleInfo {
    public string $title;
    public int $namespace;

    public function __construct(string $title, int $namespace) {
        $this->title = $title;
        $this->namespace = $namespace;
    }
}

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

    /**
     * 获取标题文本
     * @param string|Title $title
     * @param int|null $namespace
     * @return BaseTitleInfo|false
     */
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

    /**
     * 检测当前标题是否已经存在Slug
     * @param string|Title $title
     * @param int|null $namespace
     * @return bool
     */
    public static function titleSlugExists($title, $namespace = null) {
        self::initReplicaDb();

        if ($title instanceof Title) {
            $namespace = $title->getNamespace();
        }
        $titleInfo = self::getTitleInfo($title, $namespace);
        if (!$titleInfo) return false;

        $res = self::$dbr->selectField('latinize_url_slug', 'COUNT(*)', [
            'title' => $titleInfo->title,
            'namespace' => $titleInfo->namespace,
        ], __METHOD__);
        return intval($res) > 0;
    }

    /**
     * 通过Slug获取标题
     * @param string|Title $slug
     * @param int|null $namespace
     * @return Title|bool
     */
    public static function getTitleBySlug($slug, $namespace = null) {
        if ($slug instanceof Title) {
            $namespace = $namespace ?? $slug->getNamespace();
            $slug = $slug->getText();
        } else {
            $namespace = $namespace ?? NS_MAIN;
        }

        $titleText = self::getTitleTextBySlug($slug, $namespace);
        if ($titleText) {
            return Title::newFromText($titleText, $namespace);
        } else {
            return false;
        }
    }

    /**
     * 从Slug URL中获取标题
     * @param Title|string $slugUrl
     * @param int|null $namespace
     */
    public static function getTitleBySlugUrl($slugUrl, $namespace = null) {
        if ($slugUrl instanceof Title) {
            $namespace = $namespace ?? $slugUrl->getNamespace();
            $slugUrl = $slugUrl->getText();
        } else {
            $namespace = $namespace ?? NS_MAIN;
        }

        $wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();

        // 新版在URL中加了pageId，先尝试根据pageId获取标题
        $pageIdSeparator = array_merge(self::OLD_PAGE_ID_SEPARATORS, [self::PAGE_ID_SEPARATOR]);
        $pageIdSeparator = implode('|', array_map('preg_quote', $pageIdSeparator));
        if (preg_match('/^(\d+?)(' . $pageIdSeparator . ')/', $slugUrl, $matches)) {
            $pageId = intval($matches[1]);

            $wikiPage = $wikiPageFactory->newFromID($pageId);
            if ($wikiPage) {
                return $wikiPage->getTitle();
            }
        }

        // 处理旧版URL
        $titleText = self::getTitleTextBySlug($slugUrl, $namespace);
        if ($titleText) {
            return Title::newFromText($titleText, $namespace);
        } else {
            return false;
        }
    }

    /**
     * 通过Slug获取标题文本
     * @param string $slug
     * @param int $namespace
     * @return string|false
     */
    public static function getTitleTextBySlug($slug, $namespace = NS_MAIN) {
        self::initCache();

        return self::$cache->getWithSetCallback(
            self::$cache->makeKey('slug2title', $slug, $namespace),
            self::$cache::TTL_MINUTE * 10,
            function () use ($slug, $namespace) {
                self::initReplicaDb();

                $res = self::$dbr->select('latinize_url_slug', ['title'], [
                    'url_slug' => $slug,
                    'namespace' => $namespace,
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

    /**
     * 通过Title获取对应的Slug
     * @param string|Title $title
     * @param int|null $namespace
     */
    public static function getSlugByTitle($title, $namespace = null) {
        $titleInfo = self::getTitleInfo($title, $namespace);
        if (!$titleInfo) return false;

        self::initCache();
        self::initReplicaDb();

        return self::$cache->getWithSetCallback(
            self::$cache->makeKey('title2slug', $titleInfo->title, $titleInfo->namespace),
            self::$cache::TTL_MINUTE * 10,
            function () use ($titleInfo) {
                $res = self::$dbr->select('latinize_url_slug', ['url_slug'], [
                    'title' => $titleInfo->title,
                    'namespace' => $titleInfo->namespace,
                ], __METHOD__, [
                    'LIMIT' => 1,
                ]);
                if ($res->numRows() > 0) {
                    $data = $res->fetchRow();
                    return $data['url_slug'];
                } else {
                    return false;
                }
            }
        );
    }

    /**
     * 通过Title获取带有页面ID的完整Slug URL
     * @param string|Title $title
     * @param int $namespace
     */
    public static function getSlugUrlByTitle($title, $namespace = NS_MAIN) {
        if (is_string($title)) {
            $title = Title::newFromText($title, $namespace);
        }

        if (!$title) return false;

        self::initCache();

        return self::$cache->getWithSetCallback(
            self::$cache->makeKey('title2slugurl', $title->getText(), $title->getNamespace()),
            self::$cache::TTL_MINUTE * 10,
            function () use ($title) {
                $slugUrl = self::getSlugByTitle($title);
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

    /**
     * 通过Title获取对应的Slug详情
     * @param string|Title $title
     * @param int|null $namespace
     * @return array|false
     */
    public static function getSlugDataByTitle($title, $namespace = null) {
        $titleInfo = self::getTitleInfo($title, $namespace);
        if (!$titleInfo) return false;

        self::initReplicaDb();

        $res = self::$dbr->select('latinize_url_slug', '*', [
            'title' => $titleInfo->title,
            'namespace' => $titleInfo->namespace,
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

    /**
     * 添加Title的Slug信息
     * @param string|Title $title
     * @param int|null $namespace
     * @param string $slug
     * @param array $latinize
     * @param bool $isCustom
     * @return bool
     */
    public static function addTitleSlugMap($title, $namespace, $slug, $latinize = [], $isCustom = false) {
        $titleInfo = self::getTitleInfo($title, $namespace);
        if (!$titleInfo) return false;

        if (self::titleSlugExists($titleInfo->title, $titleInfo->namespace)) { // 已有Slug记录
            wfLogWarning("[LatinizeUrl] Title slug map already exists: " . $titleInfo->title);
            return false;
        }
        self::initMasterDb();

        self::$dbw->insert('latinize_url_slug', array(
            'title' => $titleInfo->title,
            'namespace' => $titleInfo->namespace,
            'url_slug' => $slug,
            'is_custom' => $isCustom ? 1 : 0,
            'latinized_words' => json_encode($latinize),
        ), __METHOD__);
        return true;
    }

    /**
     * 更新Title的Slug信息
     * @param string|Title $title
     * @param string|null $namespace
     * @param string $slug
     * @param array $latinize
     * @param bool $isCustom
     * @return bool
     */
    public static function updateTitleSlugMap($title, $namespace, $slug, $latinize = [], $isCustom = false) {
        $titleInfo = self::getTitleInfo($title, $namespace);
        if (!$titleInfo) return false;

        if (!self::titleSlugExists($titleInfo->title, $titleInfo->namespace)) {
            throw new Exception("Title slug map not exists: " . $titleInfo->title);
        }
        self::initMasterDb();
        self::initReplicaDb();
        self::initCache();

        $res = self::$dbr->selectRow('latinize_url_slug', ['id', 'url_slug'], [
            'title' => $titleInfo->title,
            'namespace' => $titleInfo->namespace,
        ], __METHOD__);

        $mapId = intval($res->id);
        $oldSlug = $res->url_slug;

        if ($oldSlug == $slug) return false; // Slug未变化

        $data = [
            'url_slug' => $slug,
            'is_custom' => $isCustom ? 1 : 0,
        ];
        if (!empty($latinize)) {
            $data['latinized_words'] = json_encode($latinize);
        }

        self::$dbw->update('latinize_url_slug', $data, [
            'id' => $mapId,
        ], __METHOD__);

        self::$cache->delete(self::$cache->makeKey('slug2title', $oldSlug, $titleInfo->namespace));
        self::$cache->delete(self::$cache->makeKey('title2slug', $titleInfo->title, $titleInfo->namespace));
        self::$cache->delete(self::$cache->makeKey('title2slugurl', $titleInfo->title, $titleInfo->namespace));
        return true;
    }

    /**
     * 添加或更新Title的Slug信息
     * @param string|Title $title
     * @param string|null $namespace
     * @param string $slug
     * @param array $latinize
     * @param bool $isCustom
     * @return bool
     */
    public static function replaceTitleSlugMap($title, $namespace, $slug, $latinize = [], $isCustom = false) {
        if (self::titleSlugExists($title, $namespace)) {
            return self::updateTitleSlugMap($title, $namespace, $slug, $latinize, $isCustom);
        } else {
            return self::addTitleSlugMap($title, $namespace, $slug, $latinize, $isCustom);
        }
    }

    /**
     * 移除Title的Slug信息
     * @param string|Title $title
     * @param int|null $namespace
     * @return bool
     */
    public static function removeTitleSlugMap($title, $namespace = null) {
        $titleInfo = self::getTitleInfo($title, $namespace);
        if (!$titleInfo) return false;
        
        self::initMasterDb();
        self::initReplicaDb();
        self::initCache();

        if (self::titleSlugExists($titleInfo->title, $titleInfo->namespace)) {
            $oldData = self::$dbr->selectRow('latinize_url_slug', ['url_slug'], [
                'title' => $titleInfo->title,
                'namespace' => $titleInfo->namespace,
            ], __METHOD__);

            self::$dbw->delete('latinize_url_slug', [
                'title' => $titleInfo->title,
                'namespace' => $titleInfo->namespace,
            ]);

            self::$cache->delete(self::$cache->makeKey('slug2title', $oldData->url_slug, $titleInfo->namespace));
            self::$cache->delete(self::$cache->makeKey('title2slug', $titleInfo->title, $titleInfo->namespace));
            self::$cache->delete(self::$cache->makeKey('title2slugurl', $titleInfo->title, $titleInfo->namespace));
            return true;
        } else {
            return false;
        }
    }

    public static function getLatinizeSortKey($string) {
        self::initReplicaDb();

        $res = self::$dbr->select('latinize_collection', ['sort_key'], [
            'title' => $string,
        ], __METHOD__, [
            'LIMIT' => 1,
        ]);

        if ($res->numRows() > 0) {
            $data = $res->fetchRow();
            return $data['sort_key'];
        } else {
            return false;
        }
    }

    public static function setLatinizeSortKey($string, $sortKey) {
        self::initMasterDb();

        self::$dbw->replace('latinize_collection', ['title'], [
            'title' => $string,
            'sort_key' => $sortKey,
        ], __METHOD__);
    }

    /**
     * 检测用户是否编辑过页面
     */
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

    /**
     * 获取Latinize转换器
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
     * 将标题转换为Latinize
     * @param Title $title - 要转换的标题
     * @param Language|StubUserLang|string|null $language - 语言
     * @return mixed 转换器
     */
    public static function parseTitleToLatinize(Title $title, Language $language) {
        try {
            $convertor = self::getConvertor($language);
            if (!$convertor) {
                wfLogWarning('No convertor found for language: ' . $language->getCode());
                return false;
            }

            if ($title->isSubpage()) {
                // 处理子页面，按照页面拆分，拼接父页面已有的Slug
                $titlePathList = explode('/', $title->getText());
                $titlePathLen = count($titlePathList);
                $unparsed = $title->getText();
                $baseSlug = false;
                for ($i = $titlePathLen - 2; $i >= 0; $i--) {
                    $titleSubPath = implode('/', array_slice($titlePathList, 0, $i + 1));
                    $baseTitle = Title::newFromText($titleSubPath, $title->getNamespace());
                    $baseSlug = self::getSlugByTitle($baseTitle);
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
                            'url_slug' => $baseSlug . '/' . $parsedSlug,
                            'latinize' => array_merge([$baseSlug, '/'], $parsed),
                        ];
                    } else {
                        return [
                            'url_slug' => $parsedSlug,
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
                        'url_slug' => $parsedSlug,
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
        $str = str_replace([' ', '&', '?', '!', '#', '%'], '-', $str);
        $str = str_replace(['-)'], [')'], $str);
        $str = preg_replace('/-+/', '-', $str);
        $str = preg_replace('/(^-|-$)/', '', $str);
        return $str;
    }
}

<?php

namespace LatinizeUrl;

use MediaWiki\Actions\ActionEntryPoint;
use MediaWiki\Api\ApiBase;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Title\Title;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserIdentity;
use MediaWiki\Title\TitleValue;
use Wikimedia\Rdbms\DBQueryError;
use MediaWiki\Output\OutputPage;
use MediaWiki\User\User;
use MediaWiki\Request\WebRequest;
use MediaWiki\Installer\DatabaseUpdater;

use LatinizeUrl\Maintenance\MigrateOldUrlSlugTable;

class Hooks {
    public static $allowedNS = [NS_MAIN, NS_TALK];

    public static function onLoadExtensionSchemaUpdates(DatabaseUpdater $updater) {
        // 更新数据库
        $dir = dirname(__DIR__) . '/sql';

        $dbType = $updater->getDB()->getType();
        // For non-MySQL/MariaDB/SQLite DBMSes, use the appropriately named file

        if (in_array($dbType, ['mysql', 'sqlite'])) {
            $updater->addExtensionTable('latinize_collection', "{$dir}/{$dbType}/latinize_collection.sql");
            $updater->addExtensionTable('latinize_url_slug', "{$dir}/{$dbType}/latinize_url_slug.sql");

            if ($updater->tableExists('url_slug')) {
                // 合并url_slug表到latinize_url_slug表
                $updater->addPostDatabaseUpdateMaintenance(MigrateOldUrlSlugTable::class);
            }
        }

        //更新文件patch
        global $IP;

        $patcher = new Patcher($IP . '/includes/actions/ActionEntryPoint.php', 'LatinizeUrl', Utils::getVersion());
        $patcher->patchInitializeParseTitleHook();
        $patcher->save();
    }

    /**
     * 自定义Hook：解析标题
     * 将标题中的自定义URL还原为原本的标题
     * @param Title $title 标题
     * @param WebRequest $request 请求
     */
    public static function onInitializeParseTitle(Title &$title, $request) {
        $service = MediaWikiServices::getInstance();

        $config = $service->getMainConfig();
        $wgLatinizeUrlForceRedirect = $config->get('LatinizeUrlForceRedirect');

        $slugText = $title->getText();

        if (in_array($title->getNamespace(), self::$allowedNS)) {
            $realTitle = Utils::getTitleBySlugUrl($slugText, $title->getNamespace());
            if ($realTitle) {
                $title = $realTitle;
                $request->setVal('title', $title->getPrefixedDBkey());
            }
        }

        if (
            $wgLatinizeUrlForceRedirect
            && !($request->getVal('action') && $request->getVal('action') != 'view') // 仅重定向View
            && !$request->getVal('veaction') // 不重定向VisualEditor
            && !defined('MW_API') // 不重定向API
            && in_array($title->getNamespace(), self::$allowedNS) // Namespace在允许自定义URL范围内
        ) { //把原标题页面重定向到拼音页面
            /** @var string 标准的Slug */
            $canonicalSlug = Utils::getSlugUrlByTitle($title);

            $slugText = str_replace('_', ' ', $slugText);
            $canonicalSlug = str_replace('_', ' ', $canonicalSlug);
            
            if ($slugText !== $canonicalSlug) {
                $newTitle = Title::newFromText($canonicalSlug, $title->getNamespace());

                if ($newTitle) {
                    $title = $newTitle;
                }
            }
        }
    }

    public static function onBeforeInitialize(Title &$title, $unused, OutputPage $output, User $user, WebRequest $request, ActionEntryPoint $entryPoint) {
    }

    /**
     * 解析页面URL时，替换为自定义URL
     * @param Title $title
     * @param string $url
     * @param array $query
     */
    public static function onGetLocalUrl(Title &$title, &$url, $query) {
        try {
            if (in_array($title->getNamespace(), self::$allowedNS) && Utils::titleSlugExists($title)) {
                $slugText = Utils::getSlugUrlByTitle($title);
                if (!$slugText) return;

                $slugTitle = Title::newFromText($slugText, $title->getNamespace());
                if (!$slugTitle) return;

                $slugEncoded = Utils::encodeUriComponent($slugTitle->getPrefixedText());
                $titleEncoded = Utils::encodeUriComponent($title->getPrefixedText());

                $url = str_replace($titleEncoded, $slugEncoded, $url);
            }
        } catch (DBQueryError $ex) {
        }
    }

    /**
     * 页面删除后，删除自定义URL
     */
    public static function onPageDeleteComplete(ProperPageIdentity $page, Authority $deleter, $reason, $pageID, $deletedRev, $logEntry, $archivedRevisionCount) {
        $title = TitleValue::newFromPage($page);
        if (in_array($title->getNamespace(), self::$allowedNS)) { //不是普通页面就跳过
            Utils::removeTitleSlugMap($title->getText());
        }
    }


    /**
     * 新建页面时，自动添加拉丁化URL
     * @param \WikiPage $wikiPage
     * @param \MediaWiki\User\UserIdentity $user
     * @param string $summary
     * @param int $flags
     * @param \MediaWiki\Revision\RevisionRecord $revisionRecord
     * @param \MediaWiki\Storage\EditResult $editResult
     */
    public static function onPageSaveComplete(&$wikiPage, $user, $summary, $flags, $revisionRecord, $editResult) {
        if (!in_array($wikiPage->getTitle()->getNamespace(), self::$allowedNS)) { //不是普通页面就跳过
            return;
        }

        if ($flags & EDIT_NEW) {
            $title = $wikiPage->getTitle();

            $parsedData = Utils::parseTitleToLatinize($title, $title->getPageLanguage());

            if ($parsedData) {
                Utils::addTitleSlugMap($title->getText(), $parsedData['url_slug'], $parsedData['latinize'], false);
            }
        }
    }

    /**
     * 页面移动后，添加拉丁化URL
     */
    public static function onPageMoveComplete(LinkTarget $old, LinkTarget $new, UserIdentity $userIdentity, $pageid, $redirid, $reason, $revision) {
        if (!in_array($new->getNamespace(), self::$allowedNS)) { //不是普通页面就跳过
            return;
        }
        $title = MediaWikiServices::getInstance()->getTitleFactory()->newFromLinkTarget($new);

        $parsedData = Utils::parseTitleToLatinize($title, $title->getPageLanguage());

        if ($parsedData) {
            Utils::addTitleSlugMap($title->getText(), $parsedData['url_slug'], $parsedData['latinize'], false);
        }
    }

    /**
     * 在API请求之前，处理标题参数
     */
    public static function onApiBeforeMain(ApiBase &$processor) {
        $request = $processor->getRequest();
        $titles = $request->getVal('titles');
        if ($titles) {
            $titles = explode('|', $titles);
            foreach ($titles as $id => $title) {
                $title = Title::newFromText($title);
                $realTitle = Utils::getTitleBySlugUrl($title, $title->getNamespace());
                if ($realTitle) {
                    $titles[$id] = $realTitle->getPrefixedText();
                }
            }
            $request->setVal('titles', implode('|', $titles));
        }
    }

    /**
     * 皮肤工具栏添加自定义URL链接
     * 添加“自定义URL”工具
     */
    public static function addToolboxLink(\Skin $skin, array &$links) {
        $service = MediaWikiServices::getInstance();
        $user = $skin->getContext()->getUser();

        $title = $skin->getRelevantTitle();
        if (in_array($title->getNamespace(), self::$allowedNS)) {
            if ($service->getPermissionManager()->userHasRight($user, 'delete') || Utils::hasUserEditedPage($title, $user)) {
                $links['TOOLBOX']['custom-url'] = [
                    'class' => false,
                    'text' => wfMessage('latinizeurl-customurl')->text(),
                    'href' => SpecialPage::getTitleFor('CustomUrl', $title->getPrefixedDBKey())->getLocalURL(),
                    'id' => 'ca-custom-url',
                ];
            }
        }
    }

    public static function onBeforePageDisplay($out) {
        if ($out->getSkin()->getSkinName() == 'timeless') {
            $out->addModules('ext.latinizeurl.timeless');
        }
    }

    /**
     * 添加拉丁化分类排序
     */
    public static function onCollationFactory($collationName, &$collationObject) {
        if ($collationName == 'latinize') {
            $collationObject = new LatinizeCollation();
        }
        return true;
    }
}

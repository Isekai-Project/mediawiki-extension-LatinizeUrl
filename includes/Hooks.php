<?php

namespace LatinizeUrl;

use MediaWiki\Actions\ActionEntryPoint;
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
use MediaWiki\Context\RequestContext;
use MediaWiki\Installer\DatabaseUpdater;

class Hooks {
    public static $allowedNS = [NS_MAIN, NS_TALK];

    public static function onLoadExtensionSchemaUpdates(DatabaseUpdater $updater) {
        //更新数据库
        $dir = dirname(__DIR__) . '/sql';

        $dbType = $updater->getDB()->getType();
        // For non-MySQL/MariaDB/SQLite DBMSes, use the appropriately named file
        if ($dbType == 'mysql') {
            $updater->addExtensionTable('url_slug', "{$dir}/mysql.sql");
        } elseif ($dbType == 'sqlite') {
            $updater->addExtensionTable('url_slug', "{$dir}/sqlite.sql");
        } else {
            throw new \Exception('Database type not currently supported');
        }

        //更新文件patch
        global $IP;

        $patcher = new Patcher($IP . '/includes/actions/ActionEntryPoint.php', 'LatinizeUrl', Utils::getVersion());
        $patcher->patchInitializeParseTitleHook();
        $patcher->save();
    }

    /* 将拼音映射转换为原标题 */
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
            && !($request->getVal('action') && $request->getVal('action') != 'view')
            && !$request->getVal('veaction')
            && !defined('MW_API')
            && in_array($title->getNamespace(), self::$allowedNS)
        ) { //把原标题页面重定向到拼音页面
            $absoluteSlug = Utils::getSlugUrlByTitle($title);

            $slugText = str_replace(' ', '_', $slugText);
            $absoluteSlug = str_replace(' ', '_', $absoluteSlug);
            
            if ($slugText !== $absoluteSlug) {
                $newTitle = Title::newFromText($absoluteSlug, $title->getNamespace());
            }

            if ($newTitle) {
                $title = $newTitle;
            }
        }
    }

    public static function onBeforeInitialize(Title &$title, $unused, OutputPage $output, User $user, WebRequest $request, ActionEntryPoint $entryPoint) {
    }

    public static function onGetArticleUrl(Title &$title, &$url, $query) {
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

    public static function onPageDeleteComplete(ProperPageIdentity $page, Authority $deleter, $reason, $pageID, $deletedRev, $logEntry, $archivedRevisionCount) {
        $title = TitleValue::newFromPage($page);
        if (in_array($title->getNamespace(), self::$allowedNS)) { //不是普通页面就跳过
            Utils::removeTitleSlugMap($title->getText());
        }
    }


    /**
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

            $parsedData = Utils::parseTitleToAscii($title, $title->getPageLanguage());

            if ($parsedData) {
                Utils::addTitleSlugMap($title->getText(), $parsedData['slug'], $parsedData['latinize']);
            }
        }
    }

    public static function onPageMoveComplete(LinkTarget $old, LinkTarget $new, UserIdentity $userIdentity, $pageid, $redirid, $reason, $revision) {
        if (!in_array($new->getNamespace(), self::$allowedNS)) { //不是普通页面就跳过
            return;
        }
        $title = MediaWikiServices::getInstance()->getTitleFactory()->newFromLinkTarget($new);

        $parsedData = Utils::parseTitleToAscii($title, $title->getPageLanguage());

        if ($parsedData) {
            Utils::addTitleSlugMap($title->getText(), $parsedData['slug'], $parsedData['latinize']);
        }
    }

    public static function onApiBeforeMain(\ApiBase &$processor) {
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

    public static function onCollationFactory($collationName, &$collationObject) {
        if ($collationName == 'latinize') {
            $collationObject = new LatinizeCollation();
        }
        return true;
    }
}

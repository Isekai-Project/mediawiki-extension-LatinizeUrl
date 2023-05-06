<?php
namespace LatinizeUrl;

use MediaWiki\Linker\LinkTarget;
use Title;
use User;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserIdentity;
use TitleValue;
use Wikimedia\Rdbms\DBQueryError;

class Hooks {
    public static $allowedNS = [NS_MAIN, NS_TALK];

    public static function onLoadExtensionSchemaUpdates($updater){
        //更新数据库
        $dir = dirname(__DIR__) . '/sql';

		$dbType = $updater->getDB()->getType();
        // For non-MySQL/MariaDB/SQLite DBMSes, use the appropriately named file
        if($dbType == 'mysql'){
            $filename = 'mysql.sql';
        } elseif($dbType == 'sqlite'){
            $filename = 'sqlite.sql';
        } else {
            throw new \Exception('Database type not currently supported');
        }
        $updater->addExtensionTable('url_slug', "{$dir}/{$filename}");
        //更新文件patch
        global $IP;

        $patcher = new Patcher($IP . '/includes/MediaWiki.php', 'LatinizeUrl', Utils::getVersion());
		$patcher->patchInitializeParseTitleHook();
		$patcher->save();
    }

    /* 将拼音映射转换为原标题 */
    public static function onInitializeParseTitle(Title &$title, $request) {
        $service = MediaWikiServices::getInstance();

        $config = $service->getMainConfig();
        $wgLatinizeUrlForceRedirect = $config->get('LatinizeUrlForceRedirect');

        if(in_array($title->getNamespace(), self::$allowedNS)){
            $realTitle = Utils::getTitleBySlugUrl($title, $title->getNamespace());
            if($realTitle){
                $title = $realTitle;
                $request->setVal('title', $title->getPrefixedDBkey());
            } elseif($wgLatinizeUrlForceRedirect
                && !($request->getVal('action') && $request->getVal('action') != 'view')
                && !$request->getVal('veaction')
                && !defined('MW_API')
                && in_array($title->getNamespace(), self::$allowedNS)) { //把原标题页面重定向到拼音页面
                $slug = Utils::getSlugUrlByTitle($title);
                if($slug) $title = Title::newFromText($slug, $title->getNamespace());
            }
        }
    }

    public static function onGetArticleUrl(\Title &$title, &$url, $query){
        try {
            if(in_array($title->getNamespace(), self::$allowedNS) && Utils::titleSlugExists($title)){
                $slug = Title::newFromText(Utils::getSlugUrlByTitle($title), $title->getNamespace());
                if ($slug) {
                    $slugEncoded = Utils::encodeUriComponent($slug->getPrefixedText());
                    $titleEncoded = Utils::encodeUriComponent($title->getPrefixedText());
                    $url = str_replace($titleEncoded, $slugEncoded, $url);
                }
            }
        } catch(DBQueryError $ex){
            
        }
    }

    public static function onPageDeleteComplete(ProperPageIdentity $page, Authority $deleter, $reason, $pageID, $deletedRev, $logEntry, $archivedRevisionCount) {
        $title = TitleValue::newFromPage( $page );
        if(in_array($title->getNamespace(), self::$allowedNS)){ //不是普通页面就跳过
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
    public static function onPageSaveComplete(&$wikiPage, $user, $summary, $flags, $revisionRecord, $editResult){
        if(!in_array($wikiPage->getTitle()->getNamespace(), self::$allowedNS)){ //不是普通页面就跳过
            return;
        }
        
        if ($flags & EDIT_NEW) {
            $title = $wikiPage->getTitle();
            $parsedData = Utils::parseTitleToAscii($title, $title->getPageLanguage());
            Utils::addTitleSlugMap($title->getText(), $parsedData['slug'], $parsedData['latinize']);
        }
    }

    public static function onPageMoveComplete(LinkTarget $old, LinkTarget $new, UserIdentity $userIdentity, $pageid, $redirid, $reason, $revision) {
        if (!in_array($new->getNamespace(), self::$allowedNS)) { //不是普通页面就跳过
            return;
        }
        $title = MediaWikiServices::getInstance()->getTitleFactory()->newFromLinkTarget($new);
        
        try {
            $parsedData = Utils::parseTitleToAscii($title, $title->getPageLanguage());
            Utils::addTitleSlugMap($title->getText(), $parsedData['slug'], $parsedData['latinize']);
        } catch (\Exception $e) {

        }
    }

    public static function onApiBeforeMain(\ApiBase &$processor){
        $request = $processor->getRequest();
        $titles = $request->getVal('titles');
        if($titles){
            $titles = explode('|', $titles);
            foreach($titles as $id => $title){
                $title = Title::newFromText($title);
                $realTitle = Utils::getTitleBySlugUrl($title, $title->getNamespace());
                if($realTitle){
                    $titles[$id] = $realTitle->getPrefixedText();
                }
            }
            $request->setVal('titles', implode('|', $titles));
        }
    }

    public static function addToolboxLink(\Skin $skin, array &$links){
        $service = MediaWikiServices::getInstance();
        $user = $skin->getContext()->getUser();
        
        $title = $skin->getRelevantTitle();
        if(in_array($title->getNamespace(), self::$allowedNS)){
            if($service->getPermissionManager()->userHasRight($user, 'delete') || Utils::hasUserEditedPage($title, $user)){
                $links['page-secondary']['custom-url'] = [
                    'class' => false,
                    'text' => wfMessage('latinizeurl-customurl')->text(),
                    'href' => \SpecialPage::getTitleFor('CustomUrl', $title->getPrefixedDBKey())->getLocalURL(),
                    'id' => 'ca-custom-url',
                ];
            }
        }
    }

    public static function onBeforePageDisplay($out){
        if($out->getSkin()->getSkinName() == 'timeless'){
            $out->addModules('ext.latinizeurl.timeless');
        }
    }

    public static function onCollationFactory($collationName, &$collationObject){
        if($collationName == 'latinize'){
            $collationObject = new LatinizeCollation();
        }
        return true;
    }
}
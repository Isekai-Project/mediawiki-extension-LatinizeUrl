<?php
namespace LatinizeUrl;

use Title;
use Article;
use OutputPage;
use User;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
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
    public static function onInitializeParseTitle(Title &$title, $request){
        global $wgLatinizeUrlForceRedirect;

        $realTitle = Utils::getTitleBySlugUrl($title);
        if($realTitle){
            $title = $realTitle;
            $request->setVal('title', $title->getDBkey());
        } elseif($wgLatinizeUrlForceRedirect
            && !($request->getVal('action') && $request->getVal('action') != 'view')
            && in_array($title->getNamespace(), self::$allowedNS)) { //把原标题页面重定向到拼音页面
            $slug = Utils::getSlugUrlByTitle($title);
            if($slug) $title = Title::newFromText($slug, $title->getNamespace());
        }
    }

    public static function onGetArticleUrl(\Title &$title, &$url, $query){
        try {
            if(Utils::titleSlugExists($title)){
                $slug = Utils::encodeUriComponent(Utils::getSlugUrlByTitle($title));
                $titleEncoded = Utils::encodeUriComponent($title->getText());
                $url = str_replace($titleEncoded, $slug, $url);
            }
        } catch(DBQueryError $ex){
            
        }
    }

    public static function onArticleDeleteComplete(Article &$article, User &$user, $reason, $id, \Content $content = null, \LogEntry $logEntry){
        if(in_array($article->getTitle()->getNamespace(), self::$allowedNS)){
            Utils::removeTitleSlugMap($article->getTitle()->getText());
        }
    }

    public static function onPageContentInsertComplete(\WikiPage &$wikiPage, User &$user, $content, $summary, $isMinor, $isWatch, $section, &$flags, $revision){
        if(!in_array($wikiPage->getTitle()->getNamespace(), self::$allowedNS)){ //不是普通页面就跳过
            return;
        }
        global $wgLatinizeUrlConfig;
        $titleText = $wikiPage->getTitle()->getText();
        $convertor = new Hanzi2Pinyin($wgLatinizeUrlConfig);
        $pinyin = $convertor->parse($titleText);
        $slug = $convertor->pinyin2String($pinyin);
        Utils::addTitleSlugMap($titleText, $slug, $pinyin);
    }

    public static function onTitleMoveComplete(Title &$title, Title &$newTitle, User $user, $oldid, $newid, $reason, $revision){
        if(!in_array($newTitle->getNamespace(), self::$allowedNS)){ //不是普通页面就跳过
            return;
        }
        global $wgLatinizeUrlConfig;
        $titleText = $newTitle->getText();
        $convertor = new Hanzi2Pinyin($wgLatinizeUrlConfig);
        $pinyin = $convertor->parse($titleText);
        $slug = $convertor->pinyin2String($pinyin);
        Utils::addTitleSlugMap($titleText, $slug, $pinyin);
    }

    public static function onApiBeforeMain(\ApiBase &$processor){
        $request = $processor->getRequest();
        $titles = $request->getVal('titles');
        if($titles){
            $titles = explode('|', $titles);
            foreach($titles as $id => $title){
                $realTitle = Utils::getTitleBySlugUrl($title);
                if($realTitle){
                    $titles[$id] = $realTitle->getText();
                }
            }
            $request->setVal('titles', implode('|', $titles));
        }
    }

    public static function onSkinTemplateOutputPageBeforeExec(\Skin $skin, \QuickTemplate $template){
        global $wgUser;
        $title = $skin->getRelevantTitle();
        if(in_array($title->getNamespace(), self::$allowedNS)){
            if($wgUser->isAllowed('delete') || Utils::hasUserEditedPage($title, $wgUser)){
                $template->data['content_navigation']['page-secondary']['custom-url'] = [
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
}
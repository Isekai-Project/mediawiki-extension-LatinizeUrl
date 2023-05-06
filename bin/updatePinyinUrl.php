<?php
require_once dirname(__DIR__, 3) . '/maintenance/Maintenance.php';
require_once dirname(__DIR__) . '/ChineseConvertor/ChineseConvertor.php';
require_once dirname(__DIR__) . '/includes/Utils.php';

use LatinizeUrl\ChineseConvertor;
use LatinizeUrl\Hanzi2Pinyin;
use LatinizeUrl\Utils;
use MediaWiki\MediaWikiServices;

/**
 * Maintenance script to normalize double-byte Latin UTF-8 characters.
 *
 * @ingroup Maintenance
 */
class UpdateLatinizeUrl extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( '更新所有页面的拼音url' );
		$this->addArg( 'pairfile', '生成新旧网址对', false );
		$this->addOption( 'force', '强制更新所有页面的url（除了自定义url的页面）' );
	}

	public function getDbType() {
		return Maintenance::DB_ADMIN;
    }

	public function execute() {
		$service = MediaWikiServices::getInstance();

		$config = $service->getMainConfig();
		$latinizeUrlConf = $config->get('LatinizeUrlConfig');
		
		$force = $this->hasOption( 'force' );
		$outputFileName = $this->getArg( 0 );
		$outputFile = false;
		if($outputFileName){
			$outputFile = fopen($outputFileName, 'w');
		}

		$dbw = $this->getDB( DB_PRIMARY );
		if ( $dbw->getType() !== 'mysql' ) {
			$this->fatalError( "This change is only needed on MySQL, quitting.\n" );
		}
        
		$convertor = new ChineseConvertor($latinizeUrlConf);

		$res = $this->findRows( $dbw );
		foreach($res as $one){
			$title = $one->page_title;
			$isCustom = boolval(intval($dbw->selectField('url_slug', 'is_custom', ['title' => $title], __METHOD__)));
            if(!$force && !$isCustom && Utils::titleSlugExists($title)) continue;

            $pinyin = $convertor->parse($title);
            $slug = $convertor->parse($pinyin);
			echo $title . ' -> ' . $slug . PHP_EOL;
			if($outputFile){
				$pair = [$this->getFullUrl($title), $this->getFullUrl($slug)];
				fwrite($outputFile, implode(' ', $pair) . "\r\n");
			}

            Utils::replaceTitleSlugMap($title, $slug, $pinyin);
		}
		
		fclose($outputFile);

		$this->output( "Done\n" );
	}

	public function searchIndexUpdateCallback( $dbw, $row ) {
		// return $this->updateSearchIndexForPage( $dbw, $row->si_page );
	}

	private function getFullUrl($pageName) {
		$service = MediaWikiServices::getInstance();

		$config = $service->getMainConfig();
		$wgServer = $config->get('Server');
		$wgArticlePath = $config->get('ArticlePath');
		$wgUsePathInfo = $config->get('UsePathInfo');
		$pageName = implode("/", array_map("urlencode", explode("/", $pageName)));
		if($wgUsePathInfo){
			return $wgServer . str_replace('$1', $pageName, $wgArticlePath);
		} else {
			return $wgServer . '/index.php?title=' . $pageName;
		}
	}

	private function findRows( $dbi ) {
		return $dbi->select('page', ['page_title'], [
            'page_namespace' => NS_MAIN,
        ], __METHOD__, []);
	}
}

$maintClass = UpdateLatinizeUrl::class;
require_once RUN_MAINTENANCE_IF_MAIN;
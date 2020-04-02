<?php
use LatinizeUrl\Hanzi2Pinyin;
require_once dirname(__DIR__, 2) . '/maintenance/Maintenance.php';
require('includes/Hanzi2Pinyin.php');

class LatinizeUrlTest extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( '测试拼音URL' );
	}

	public function execute() {
        global $wgLatinizeUrlConfig;
		$parser = new Hanzi2Pinyin($wgLatinizeUrlConfig);
		$pinyin = $parser->parse('偏爱~But Destined To Be');
		$url = $parser->pinyin2String($pinyin);
		var_dump($url);
	}
}

$maintClass = LatinizeUrlTest::class;
require_once RUN_MAINTENANCE_IF_MAIN;
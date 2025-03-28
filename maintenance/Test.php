<?php
namespace LatinizeUrl\Maintenance;

use MediaWiki\Title\Title;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class Test extends Maintenance {
    public function __construct() {
		parent::__construct();
		$this->addDescription('测试');
	}

    public function execute() {
        $title = Title::newFromText('Test_Page');

        $titleText = $title->getText();

        $this->output($titleText);
    }
}

$maintClass = Test::class;
require_once RUN_MAINTENANCE_IF_MAIN;
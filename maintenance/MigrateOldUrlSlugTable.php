<?php

namespace LatinizeUrl\Maintenance;

use MediaWiki\Maintenance\LoggedUpdateMaintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class MigrateOldUrlSlugTable extends LoggedUpdateMaintenance {

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'LatinizeUrl' );
		$this->addDescription(
			'Copy old url_slug table to latinize_url_slug table and drop old url_slug table'
		);
		$this->addOption(
			'sleep',
			'Sleep time (in seconds) between every batch. Default: 1 seconds',
			false,
			true
		);
		$this->setBatchSize( 1000 );
	}

	protected function doDBUpdates() {
		$this->output( "Migrating the url_slug table to latinize_url_slug table...\n" );

		$batchSize = $this->getBatchSize();
		$sleep = (int)$this->getOption( 'sleep', 1 );

		$dbw = $this->getDB( DB_PRIMARY );
		if ( !$dbw->tableExists( 'latinize_url_slug', __METHOD__ ) ) {
			$this->output( "Run update.php to create latinize_url_slug table.\n" );
			return false;
		}
		
		$count = $dbw->selectField(
			'url_slug',
			'COUNT(*)',
			[],
			__METHOD__
		);
		if ( $count === 0 ) {
			$this->output( "No rows to migrate.\n" );
			return true;
		}

		for ( $i = 0; $i < $count; $i += $batchSize ) {
			$this->output( "Migrating rows {$i} to " . ( $i + $batchSize ) . "\n" );
			$rows = $dbw->select(
				'url_slug',
				[ 'title', 'slug', 'is_custom', 'latinize' ],
				[],
				__METHOD__,
				[
					'LIMIT' => $batchSize,
					'OFFSET' => $i,
				]
			);

			foreach ( $rows as $row ) {
				$row->slug = str_replace('_', ' ', $row->slug);
				$dbw->replace(
					'latinize_url_slug',
					['title'],
					[
						'title' => $row->title,
						'url_slug' => $row->slug,
						'is_custom' => $row->is_custom,
						'latinized_words' => $row->latinize,
					],
					__METHOD__
				);
			}
		}

		$this->output(
			"Completed migration of url_slug table to latinize_url_slug table.\n"
		);

		$dbw->dropTable( 'url_slug', __METHOD__ );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getUpdateKey() {
		return 'migrate url_slug table to latinize_url_slug table';
	}
}

$maintClass = MigrateOldUrlSlugTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;

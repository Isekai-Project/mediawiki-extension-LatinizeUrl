<?php

namespace LatinizeUrl\Jobs;

use MediaWiki\MediaWikiServices;
use LatinizeUrl\Hooks;

class RetryJob extends \Job implements \GenericParameterJob {
    public function __construct(array $params) {
        parent::__construct('latinizeUrlRetry', $params);
        $this->removeDuplicates = false;
    }

    /** @internal Enqueue only after the caller's transaction commits. */
    public static function schedule(int $pageId, int $attempt, int $maxRetries): void {
        if ($pageId <= 0 || $attempt > $maxRetries || $maxRetries <= 0) {
            return;
        }
        $services = MediaWikiServices::getInstance();
        $db = $services->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        $db->onTransactionCommitOrIdle(static function () use ($services, $pageId, $attempt, $maxRetries) {
            try {
                $services->getJobQueueGroup()->push(new self([
                    'pageId' => $pageId, 'attempt' => $attempt, 'maxRetries' => $maxRetries
                ]));
            } catch (\Throwable $e) {
                wfDebugLog('LatinizeUrl', "Retry enqueue failed: pageId=$pageId attempt=$attempt error=" . $e->getMessage());
                throw $e;
            }
        }, __METHOD__);
    }

    public function run() {
        $services = MediaWikiServices::getInstance();
        $pageId = (int)$this->params['pageId'];
        $attempt = (int)$this->params['attempt'];
        $max = (int)$this->params['maxRetries'];
        if ($attempt < 1 || $attempt > $max) {
            return true;
        }
        $page = $services->getWikiPageFactory()->newFromID($pageId, 'fromdbmaster');
        if (!$page || !in_array($page->getTitle()->getNamespace(), Hooks::$allowedNS)) {
            return true;
        }
        $title = $page->getTitle();
        $reason = 'conversion or mapping returned false';
        try {
            if ($services->getService('LatinizeUrl.LatinizeUrlService')->ensureAutomaticSlug($title)) {
                return true;
            }
        } catch (\Throwable $e) {
            $reason = get_class($e) . ': ' . $e->getMessage();
        }
        wfDebugLog('LatinizeUrl', "Automatic slug failed: pageId=$pageId title=" . $title->getPrefixedDBkey() .
            " attempt=$attempt/$max error=$reason" . ($attempt === $max ? ' retries exhausted' : ''));
        self::schedule($pageId, $attempt + 1, $max);
        return true;
    }
}

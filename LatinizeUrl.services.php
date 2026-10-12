<?php

use LatinizeUrl\LatinizeUrlService;
use MediaWiki\MediaWikiServices;

return [
    'LatinizeUrl.LatinizeUrlService' => static function (MediaWikiServices $services) {
        return new LatinizeUrlService(
            $services->getDBLoadBalancer()->getConnection(DB_PRIMARY),
            $services->getDBLoadBalancer()->getConnection(DB_REPLICA),
            $services->getMainWANObjectCache(),
            $services->getTitleFactory(),
            $services->getWikiPageFactory(),
            $services->getHookContainer(),
            $services->getContentLanguage()
        );
    }
];

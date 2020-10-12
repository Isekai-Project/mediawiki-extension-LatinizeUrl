<?php
/* Only support api parse yet */
namespace LatinizeUrl;

use Exception;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;

class JapaneseConvertor extends BaseConvertor {
    private $config;
    private static $standalone = null;

    public static function standalone(){
        if(!self::$standalone){
            global $wgLatinizeUrlJapaneseConvertorConfig;
            self::$standalone = new self($wgLatinizeUrlJapaneseConvertorConfig);
        }
        return self::$standalone;
    }

    public static function onGetConvertor($langCode, &$convertor){
        if(in_array($langCode, ['ja', 'ja-jp'])){
            $convertor = self::standalone();
        }
        return true;
    }

    public function __construct($config){
        $this->config = $config;
    }

    public function parse($kanji){
        if(!isset($this->config['url'])){
            throw new Exception('LatinizeUrl remote api url not set.');
        }
        $factory = MediaWikiServices::getInstance()->getHttpRequestFactory();
        $req = $factory->create($this->config['url'], [
            'method' => 'POST',
            'postData' => [
                'sentence' => $kanji
            ],
        ], __METHOD__);
        $status = \Status::wrap($req->execute());
        if(!$status->isOK()){
            throw new Exception('Cannot use LatinizeUrl remote api.');
        }
        $json = \FormatJson::decode($req->getContent(), true);
        if(isset($json["error"])){
            throw new Exception('LatinizeUrl remote api error: ' . $json["error"]);
        }
        if(!isset($json["status"]) || $json["status"] !== 1){
            throw new Exception('Cannot use LatinizeUrl remote api.');
        }
        return $json["data"];
    }
}
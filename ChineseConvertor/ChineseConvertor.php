<?php
namespace LatinizeUrl;

use Exception;
use MWHttpRequest;
use MediaWiki\Http\HttpRequestFactory;
use Fukuball\Jieba\Jieba;
use Fukuball\Jieba\Finalseg;
use Fukuball\Jieba\Posseg;
use Overtrue\Pinyin\Pinyin;

class ChineseConvertor extends BaseConvertor {
    private $config;
    private static $standalone = null;
    private static $libLoaded = false;
    private static $jiebaLoaded = false;
    private static $pinyinParser = null;

    public static function standalone(){
        if(!self::$standalone){
            global $wgLatinizeUrlChineseConvertorConfig;
            self::$standalone = new self($wgLatinizeUrlChineseConvertorConfig);
        }
        return self::$standalone;
    }

    public static function onGetConvertor($langCode, &$convertor){
        if(in_array($langCode, ['zh-cn', 'zh-hans'])){
            $convertor = self::standalone();
        }
        return true;
    }

    public function __construct($config){
        $this->config = $config;
    }

    public function parse($hanzi){
        $method = $this->config['parser'] . 'Parse';
            
        if(is_callable([$this, $method])){
            return call_user_func([$this, $method], $hanzi);
        } else {
            throw new Exception('Cannot find pinyin parser: ' . $this->config['parser']);
        }
    }

    private function filteJiebaTag($segList){
        $ret = [];
        foreach($segList as $seg){
            if($seg['tag'] === 'uv' || $seg['tag'] === 'ud'){ //介词
                $index = count($ret) - 1;
                $ret[$index] .= '的';
            } else {
                $ret[] = $seg['word'];
            }
        }
        return $ret;
    }

    /**
     * 使用php内部方法实现汉字转拼音
     */
    public function innerParse($hanzi){
        $ret = [];
        if(!self::$libLoaded){
            require_once(dirname(__DIR__) . '/vendor/autoload.php');
            self::$libLoaded = true;
        }
        $originalSentenceList = explode('/', $hanzi);
        $sentenceList = [];
        if(isset($this->config['cutWord']) && $this->config['cutWord']){ //需要分词
            if(!self::$jiebaLoaded){
                ini_set('memory_limit', '1024M');
                Jieba::init(['test' => true]);
                Finalseg::init();
                Posseg::init();
                Jieba::loadUserDict(dirname(__DIR__) . '/data/userDict.txt');
                self::$jiebaLoaded = true;
            }
            $length = count($originalSentenceList);
            for($i = 0; $i < $length; $i ++){
                $sentence = $originalSentenceList[$i];
                $sentenceList[] = $this->filteJiebaTag(Posseg::cut($sentence));
                if($i + 1 < $length){
                    $sentenceList[] = '/';
                }
            }
        } else {
            $length = count($originalSentenceList);
            for($i = 0; $i < $length; $i ++){
                $sentence = $originalSentenceList[$i];
                $sentenceList[] = [$sentence];
                if($i + 1 < $length){
                    $sentenceList[] = '/';
                }
            }
        }
        //分词后，进行拼音标注
        if(!self::$pinyinParser){
            self::$pinyinParser = new Pinyin();
        }
        foreach($sentenceList as $segList){
            if(is_array($segList)){
                $segPinyin = [];
                foreach($segList as $seg){
                    $segPinyin[] = self::$pinyinParser->convert($seg,
                        PINYIN_NO_TONE | PINYIN_UMLAUT_V | PINYIN_KEEP_PUNCTUATION | PINYIN_KEEP_ENGLISH | PINYIN_KEEP_NUMBER);
                }
                $ret[] = $segPinyin;
            } else {
                $ret[] = $segList;
            }
        }
        return $ret;
    }

    /**
     * 使用hook进行汉字转拼音
     */
    public function hookParse($hanzi){
        $pinyinList = null;
        \Hooks::run('Pinyin2Hanzi', [$hanzi, &$pinyinList]);
        if(!$pinyinList){
            if(isset($this->config['fallback'])){
                return $this->parse($hanzi, $this->config['fallback']);
            } else {
                throw new Exception('Hook Pinyin2Hanzi never handled.');
            }
        }
    }

    private function fallbackOrException($hanzi, $message){
        if(isset($this->config['fallback']) && $this->config['fallback'] != false){
            return $this->parse($hanzi, $this->config['fallback']);
        } else {
            throw new Exception($message);
        }
    }

    public function apiParse($hanzi){
        if(!isset($this->config['url'])){
            throw new Exception('LatinizeUrl remote api url not set.');
        }
        $factory = new HttpRequestFactory();
        $req = $factory->create($this->config['url'], [
            'method' => 'POST',
            'postData' => [
                'sentence' => $hanzi
            ],
        ], __METHOD__);
        $status = \Status::wrap($req->execute());
        if(!$status->isOK()){
            $this->fallbackOrException($hanzi, 'Cannot use LatinizeUrl remote api.');
        }
        $json = \FormatJson::decode($req->getContent(), true);
        if(isset($json["error"])){
            $this->fallbackOrException($hanzi, 'LatinizeUrl remote api error: ' . $json["error"]);
        }
        if(!isset($json["status"]) || $json["status"] !== 1){
            $this->fallbackOrException($hanzi, 'Cannot use LatinizeUrl remote api.');
        }
        return $json["data"];
    }
}
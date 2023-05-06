<?php
namespace LatinizeUrl;

class Patcher {
    private $file;
    private $content;
    private $tag;
    private $version;

    public function __construct($file, $tag, $version){
        $this->file = $file;
        $this->content = file_get_contents($file);
        $this->tag = $tag;
        $this->version = $version;
    }

    public function findPatchVersion($name){
        $regex = '/\/\/ Start ' . $this->tag . ' ([0-9.\-]+) ' . $name . ' Patch\n.*?\/\/ End ' . $this->tag . ' [0-9.\-]+ ' . $name . ' Patch/is';
        if(preg_match($regex, $this->content, $matches, PREG_OFFSET_CAPTURE)){
            $ret = [];
            $ret['start'] = $matches[0][1];
            $ret['end'] = $ret['start'] + strlen($matches[0][0]);
            $ret['version'] = $matches[1][0];
            return $ret;
        } else {
            return false;
        }
    }

    public function patchInitializeParseTitleHook(){
        $patchName = 'InitializeParseTitleHook';
        $patchContent = ['MediaWikiServices::getInstance()->getHookContainer()->run( \'InitializeParseTitle\', [ &$ret, $request ] );'];
        $patchFinalContent = $this->makePatchContent($patchName, $patchContent, 2);
        $currentPatch = $this->findPatchVersion($patchName);
        if ($currentPatch) {
            if($currentPatch['version'] != $this->version){ //需要更新
                $this->content = substr($this->content, 0, $currentPatch['start'] - 2)
                    . $patchFinalContent
                    . substr($this->content, $currentPatch['end'] + 1);
            }
        } else { //打新的补丁
            $regex = '/(?!private function parseTitle\(\) \{(.*?))(?=[\t ]+return \$ret;)/is';
            if(preg_match($regex, $this->content, $matches, PREG_OFFSET_CAPTURE)){
                $splitPos = $matches[0][1];
                $this->content = substr($this->content, 0, $splitPos)
                    . $patchFinalContent
                    . substr($this->content, $splitPos);
            }
        }
    }

    public function makePatchContent($name, $content, $indent = 0, $indentChar = "\t"){
        if(!is_array($content)) $content = explode("\n", $content);
        $lines = array_merge([
            '// Start ' . $this->tag . ' ' . $this->version . ' ' . $name . ' Patch',
            '// This code is added by ' . $this->tag . ' extension, Do not remove this code until you uninstall ' . $this->tag . ' extension.',
        ], $content, [
            '// End ' . $this->tag . ' ' . $this->version . ' ' . $name . ' Patch',
        ]);
        $contentText = '';
        foreach($lines as $line){
            $contentText .= str_repeat($indentChar, $indent) . $line . "\n";
        }
        return $contentText;
    }

    public function save($file = null){
        if(!$file) $file = $this->file;
        file_put_contents($file, $this->content);
    }
}
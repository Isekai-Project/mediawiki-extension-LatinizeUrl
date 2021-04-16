<?php
namespace LatinizeUrl;

use FormSpecialPage;
use MediaWiki\MediaWikiServices;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;

class SpecialCustomUrl extends FormSpecialPage
{
    /**
     * @var \Title $title
     */
    protected $title;
    protected $slug;
    protected $isAdmin;
    protected $userEditedPage;

    public function __construct()
    {
        parent::__construct('CustomUrl', '', false);
    }

    public function doesWrites()
    {
        return true;
    }

    public function execute($par)
    {
        parent::execute($par);

        $this->getSkin()->setRelevantTitle($this->title);
        $out = $this->getOutput();
        $out->setPageTitle($this->msg('latinizeurl-customurl', $this->title->getPrefixedText()));
    }

    protected function setParameter( $par ) {
        $service = MediaWikiServices::getInstance();
		$title = \Title::newFromText( $par );
		$this->title = $title;

		if ( !$title ) {
			throw new \ErrorPageError( 'notargettitle', 'notargettext' );
		}
		if ( !$title->exists() ) {
			throw new \ErrorPageError( 'nopagetitle', 'nopagetext' );
        }
        
        
        $isAdmin = $service->getPermissionManager()->userHasRight($this->getUser(), 'delete');
        $this->isAdmin = $isAdmin;
        $userEditedPage = Utils::hasUserEditedPage($this->title, $this->getUser());
        $this->userEditedPage = $userEditedPage;
    
        $this->slug = $this->getCurrentSlug();

        if(!$this->hasAccess()){
            throw new \PermissionsError('move');
        }
    }

    protected function hasAccess(){
        return $this->isAdmin || $this->userEditedPage;
    }
    
    protected function showForm($err, $isPermErr){

    }

    private function getCurrentSlug(){
        $slug = Utils::getSlugUrlByTitle($this->title);
        if($slug){
            return $slug;
        } else {
            return $this->title->getText();
        }
    }

    protected function getFormFields() {
        $fields = [];

        $fields['slug'] = [
            'type' => 'text',
            'label-message' => 'customurl-url-field-label',
            'help-message' => 'customurl-url-field-help',
            'default' => $this->getCurrentSlug(),
        ];

        if($this->title->hasSubpages()){
            $fields['rename-subpage'] = [
                'type' => 'check',
                'label-message' => 'rename-subpage-checkbox-label',
                'default' => false,
            ];
        }

		return $fields;
    }
    
    public function onSubmit(array $data, \HTMLForm $form = null ) {
        $originSlug = Utils::getSlugByTitle($this->title);
        $slug = $data['slug'];
        $latinize = [];
        if(empty($slug)){ //自动生成
            $parsedData = Utils::parseTitleToAscii($this->title, $this->title->getPageLanguage());
            $slug = $parsedData['slug'];
            $latinize = $parsedData['latinize'];
            $custom = 0;
        } else {
            $slug = str_replace('_', ' ', $slug);
            $latinize = [$slug];
            $custom = 1;
        }

        if(Utils::titleSlugExists($this->title)){
            $realSlug = Utils::updateTitleSlugMap($this->title->getText(), $slug, $latinize, $custom);
        } else {
            $realSlug = Utils::addTitleSlugMap($this->title->getText(), $slug, $latinize, $custom);
        }
        
        if(isset($data['rename-subpage']) && $data['rename-subpage']){
            //更新子页面的slug
            $subpages = $this->title->getSubpages();
            $originSlugLen = strlen($originSlug);
            /** @var \Title $subpage */
            foreach($subpages as $subpage){
                $originSubpaeSlug = Utils::getSlugByTitle($subpage);
                if(strpos($originSubpaeSlug, $originSlug) === 0){
                    $newSubpageSlug = $realSlug . substr($originSubpaeSlug, $originSlugLen);
                    var_dump($newSubpageSlug);
                    Utils::updateTitleSlugMap($subpage->getText(), $newSubpageSlug, [$newSubpageSlug], 1);
                }
            }
        }
        $this->slug = $realSlug;
        return true;
    }

    public function onSuccess(){
        $out = $this->getOutput();
        $out->addWikiMsg('customurl-set-success', $this->title->getText(), str_replace(' ', '_', $this->slug));
    }
}

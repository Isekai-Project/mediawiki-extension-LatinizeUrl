<?php
namespace LatinizeUrl;

use FormSpecialPage;

class SpecialCustomUrl extends FormSpecialPage
{
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
		$title = \Title::newFromText( $par );
		$this->title = $title;

		if ( !$title ) {
			throw new \ErrorPageError( 'notargettitle', 'notargettext' );
		}
		if ( !$title->exists() ) {
			throw new \ErrorPageError( 'nopagetitle', 'nopagetext' );
        }
        
        $isAdmin = $this->getUser()->isAllowed('delete');
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

		return $fields;
    }
    
    public function onSubmit(array $data, \HTMLForm $form = null ) {
        global $wgLatinizeUrlConfig;
        $slug = $data['slug'];
        if(empty($slug)){ //自动生成
            $titleText = $this->title->getText();
            $convertor = new Hanzi2Pinyin($wgLatinizeUrlConfig);
            $latinize = $convertor->parse($titleText);
            $slug = Utils::wordListToUrl($latinize);
        } else {
            $slug = str_replace('_', ' ', $slug);
        }

        if(Utils::titleSlugExists($this->title)){
            $realSlug = Utils::updateTitleSlugMap($this->title->getText(), $slug, [], 1);
        } else {
            $realSlug = Utils::addTitleSlugMap($this->title->getText(), $slug, [$slug], 1);
        }
        
        $this->slug = $realSlug;
        return true;
    }

    public function onSuccess(){
        $out = $this->getOutput();
        $out->addWikiMsg('customurl-set-success', $this->title->getText(), str_replace(' ', '_', $this->slug));
    }
}

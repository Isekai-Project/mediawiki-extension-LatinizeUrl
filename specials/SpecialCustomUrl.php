<?php

namespace LatinizeUrl;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\Html\Html;
use MediaWiki\Session\CsrfTokenSet;
use MediaWiki\Tests\Session\CsrfTokenSetTest;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleArrayFromResult;
use ThrottledError;

class SpecialCustomUrl extends UnlistedSpecialPage {
	/**
	 * @var string
	 */
	protected $target;

	/**
	 * @var Title
	 */
	protected $title;

	/** @var int */
	protected $pageId = null;

	/** @var string */
	protected $slug;

	/** @var bool */
	protected $isAdmin;

	/** @var bool */
	protected $userEditedPage;

	/** @var NamespaceInfo */
	private $nsInfo;

	/** @var LinkBatchFactory */
	private $linkBatchFactory;

	public function __construct() {
		parent::__construct('CustomUrl', '', false);

		$service = MediaWikiServices::getInstance();
		$this->nsInfo = $service->getNamespaceInfo();
		$this->linkBatchFactory = $service->getLinkBatchFactory();
	}

	public function doesWrites() {
		return true;
	}

	public function execute($par) {
		$this->useTransactionalTimeLimit();

		$this->checkReadOnly();

		$this->setHeaders();
		$this->outputHeader();

		$service = MediaWikiServices::getInstance();

		$request = $this->getRequest();

		$this->target = $par ?? $request->getText('target');
		$title = Title::newFromText($this->target);
		$this->title = $title;
		$this->getSkin()->setRelevantTitle($this->title);

		if (!$this->title->isMainPage()) { // 首页不显示PageId
			$wikiPageFactory = $service->getWikiPageFactory();
			$wikiPage = $wikiPageFactory->newFromTitle($title);
			if ($wikiPage) {
				$this->pageId = $wikiPage->getId();
			}
		}

		$user = $this->getUser();

		if (!$title) {
			throw new \ErrorPageError('notargettitle', 'notargettext');
			return;
		}
		if (!$title->exists()) {
			throw new \ErrorPageError('nopagetitle', 'nopagetext');
		}

		$isAdmin = $service->getPermissionManager()->userHasRight($this->getUser(), 'move');
		$this->isAdmin = $isAdmin;
		$userEditedPage = Utils::hasUserEditedPage($this->title, $this->getUser());
		$this->userEditedPage = $userEditedPage;

		if (!$this->hasAccess()) {
			throw new \PermissionsError('move');
		}

		$this->slug = $this->getCurrentSlug();

		$csrfTokenObj = $request->getSession()->getToken();

		if ($request->getRawVal('action') == 'submit' && $request->wasPosted() && $csrfTokenObj->match($request->getVal('wpEditToken'))) {
			$this->doSubmit();
		} else {
			$this->showForm([]);
		}
	}

	protected function hasAccess() {
		return $this->isAdmin || $this->userEditedPage;
	}

	protected function showForm($err, $isPermError = false) {
		$user = $this->getUser();
		$out = $this->getOutput();
		$request = $this->getRequest();
		$csrfTokenObj = $request->getSession()->getToken();

		$out->setPageTitle($this->msg('latinizeurl-customurl')->text());
		$out->addBacklinkSubtitle($this->title);
		$out->addModuleStyles([
			'mediawiki.special',
			'mediawiki.interface.helpers.styles'
		]);
		$out->addModules('mediawiki.misc-authed-ooui');

		$out->enableOOUI();

		$fields = [];

		$fields[] = new \OOUI\FieldLayout(
			new \OOUI\TextInputWidget([
				'name' => 'wpSlug',
				'id' => 'wpSlug',
				'value' => $this->getCurrentSlug(),
			]),
			[
				'label' => $this->msg('customurl-url-field-label')->text(),
				'help' => $this->msg('customurl-url-field-help')->text(),
				'align' => 'top',
			]
		);

		if ($this->title->hasSubpages()) {
			$fields[] = new \OOUI\FieldLayout(
				new \OOUI\CheckboxInputWidget([
					'name' => 'wpRenameSubpage',
					'id' => 'wpRenameSubpage',
					'value' => '1',
				]),
				[
					'label' => $this->msg('rename-subpage-checkbox-label')->text(),
					'align' => 'inline',
				]
			);
		}

		$fields[] = new \OOUI\FieldLayout(
			new \OOUI\ButtonInputWidget([
				'name' => 'wpConfirm',
				'value' => $this->msg('htmlform-submit')->text(),
				'label' => $this->msg('htmlform-submit')->text(),
				'flags' => ['primary', 'progressive'],
				'type' => 'submit',
			]),
			[
				'align' => 'top',
			]
		);

		$fieldset = new \OOUI\FieldsetLayout([
			'label' => $this->msg('customurl-legend')->text(),
			'id' => 'mw-customurl-table',
			'items' => $fields,
		]);

		$form = new \OOUI\FormLayout([
			'method' => 'post',
			'action' => $this->getPageTitle($this->target)->getLocalURL('action=submit'),
			'id' => 'customurl',
		]);

		$form->appendContent(
			$fieldset,
			new \OOUI\HtmlSnippet(
				Html::hidden('wpEditToken', $csrfTokenObj->toString())
			)
		);

		$out->addHTML(
			new \OOUI\PanelLayout([
				'classes' => ['movepage-wrapper', 'customurl-wrapper'],
				'expanded' => false,
				'padded' => true,
				'framed' => true,
				'content' => $form,
			])
		);

		if ($this->title->hasSubpages()) {
			$this->showSubpages($this->title);
		}
	}

	private function getCurrentSlug() {
		$slug = Utils::getSlugByTitle($this->title);
		if ($slug) {
			return $slug;
		} else {
			return $this->title->getText();
		}
	}

	public function doSubmit() {
		$user = $this->getUser();

		if ($user->pingLimiter('customurl')) {
			throw new ThrottledError;
		}

		$request = $this->getRequest();
		$slug = $request->getText('wpSlug');
		$renameSubpages = $request->getBool('wpRenameSubpage');

		$originSlug = Utils::getSlugByTitle($this->title);

		$latinize = [];
		if (empty($slug)) { //自动生成
			$parsedData = Utils::parseTitleToLatinize($this->title, $this->title->getPageLanguage());
			$slug = $parsedData['url_slug'];
			$latinize = $parsedData['latinize'];
			$custom = 0;
		} else {
			$slug = str_replace('_', ' ', $slug);
			$latinize = [$slug];
			$custom = 1;
		}

		Utils::replaceTitleSlugMap($this->title->getText(), $slug, $latinize, $custom);

		if ($renameSubpages) {
			//更新子页面的slug
			$subpages = $this->title->getSubpages();
			$originSlugLen = strlen($originSlug);
			/** @var \Title $subpage */
			foreach ($subpages as $subpage) {
				$originSubpaeSlug = Utils::getSlugByTitle($subpage);
				if (strpos($originSubpaeSlug, $originSlug) === 0) {
					$newSubpageSlug = $slug . substr($originSubpaeSlug, $originSlugLen);
					Utils::updateTitleSlugMap($subpage->getText(), $newSubpageSlug, [$newSubpageSlug], 1);
				}
			}
		}
		$this->slug = $slug;

		$this->onSuccess();
		return true;
	}

	/**
	 * Show subpages of the page being moved. Section is not shown if both current
	 * namespace does not support subpages and no talk subpages were found.
	 *
	 * @param Title $title Page being moved.
	 */
	private function showSubpages($title) {
		$nsHasSubpages = $this->nsInfo->hasSubpages($title->getNamespace());
		$subpages = $title->getSubpages();
		$count = $subpages instanceof TitleArrayFromResult ? $subpages->count() : 0;

		$titleIsTalk = $title->isTalkPage();

		$talkPage = $title->getTalkPageIfDefined();
		if ($talkPage) {
			$subpagesTalk = $talkPage->getSubpages();
		} else {
			$subpagesTalk = [];
		}
		
		$countTalk = $subpagesTalk instanceof TitleArrayFromResult ? $subpagesTalk->count() : 0;
		$totalCount = $count + $countTalk;

		if (!$nsHasSubpages && $countTalk == 0) {
			return;
		}

		$this->getOutput()->wrapWikiMsg(
			'== $1 ==',
			['movesubpage', ($titleIsTalk ? $count : $totalCount)]
		);

		if ($nsHasSubpages) {
			$this->showSubpagesList($subpages, $count, 'movesubpagetext', true);
		}

		if (!$titleIsTalk && $countTalk > 0) {
			$this->showSubpagesList($subpagesTalk, $countTalk, 'movesubpagetalktext');
		}
	}

	private function showSubpagesList($subpages, $pagecount, $wikiMsg, $noSubpageMsg = false) {
		$out = $this->getOutput();

		# No subpages.
		if ($pagecount == 0 && $noSubpageMsg) {
			$out->addWikiMsg('movenosubpage');
			return;
		}

		$out->addWikiMsg($wikiMsg, $this->getLanguage()->formatNum($pagecount));
		$out->addHTML("<ul>\n");

		$linkBatch = $this->linkBatchFactory->newLinkBatch($subpages);
		$linkBatch->setCaller(__METHOD__);
		$linkBatch->execute();
		$linkRenderer = $this->getLinkRenderer();

		foreach ($subpages as $subpage) {
			$link = $linkRenderer->makeLink($subpage);
			$out->addHTML("<li>$link</li>\n");
		}
		$out->addHTML("</ul>\n");
	}

	public function onSuccess() {
		$out = $this->getOutput();
		$out->setPageTitle($this->msg('latinizeurl-customurl')->text());
		$out->addWikiMsg('customurl-set-success', $this->title->getText(), str_replace(' ', '_', $this->slug));
	}

	protected function getGroupName() {
		return 'pagetools';
	}
}

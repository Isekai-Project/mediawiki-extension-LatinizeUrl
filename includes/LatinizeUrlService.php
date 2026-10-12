<?php

namespace LatinizeUrl;

use LatinizeUrl\Service\SlugRepository;
use LatinizeUrl\Service\SlugUrlResolver;
use LatinizeUrl\Service\SortKeyStore;
use LatinizeUrl\Service\TitleLatinizer;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Language\Language;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

class LatinizeUrlService {
    private SlugRepository $repository;
    private SlugUrlResolver $urls;
    private TitleLatinizer $latinizer;
    private SortKeyStore $sortKeys;

    public function __construct(private IDatabase $primary, IReadableDatabase $replica,
        private WANObjectCache $cache, private TitleFactory $titles, WikiPageFactory $pages,
        private HookContainer $hooks, Language $language) {
        $this->repository = new SlugRepository($primary, $replica, $cache);
        $this->urls = new SlugUrlResolver($this->repository, $titles, $pages);
        $this->latinizer = new TitleLatinizer($this->repository, $titles, $hooks, $language);
        $this->sortKeys = new SortKeyStore($primary, $replica);
    }

    public function titleSlugExists($title, $namespace = null) {
        if ($title instanceof Title) {
            $namespace = $title->getNamespace();
        }
        $info = Utils::getTitleInfo($title, $namespace);
        return $info ? $this->repository->getData($info) !== false : false;
    }

    public function getTitleBySlug($slug, $namespace = null) {
        return $this->urls->resolve($slug, $namespace);
    }

    public function getTitleBySlugUrl($slugUrl, $namespace = null) {
        return $this->urls->resolveUrl($slugUrl, $namespace);
    }

    public function getSlugByTitle($title, $namespace = null) {
        $info = Utils::getTitleInfo($title, $namespace);
        return $info ? $this->repository->getSlug($info) : false;
    }

    public function getSlugUrlByTitle($title, $namespace = NS_MAIN) {
        return $this->urls->forTitle($title, $namespace);
    }

    public function getSlugDataByTitle($title, $namespace = null) {
        $info = Utils::getTitleInfo($title, $namespace);
        return $info ? $this->repository->getData($info) : false;
    }

    public function addTitleSlugMap($title, $namespace, $slug, $latinize = [], $isCustom = false) {
        $info = Utils::getTitleInfo($title, $namespace);
        if (!$info || !$this->repository->add($info, $slug, $latinize, $isCustom)) {
            return false;
        }
        $this->notify($info, null, $slug);
        return true;
    }

    public function updateTitleSlugMap($title, $namespace, $slug, $latinize = [], $isCustom = false) {
        $info = Utils::getTitleInfo($title, $namespace);
        if (!$info) {
            return false;
        }
        $old = $this->repository->getData($info, true);
        $pageTitle = $this->titles->makeTitle($info->namespace, $info->title);
        $oldUrl = $old ? $this->urls->format($pageTitle, $old['url_slug']) : null;
        if (!$this->repository->update($info, $slug, $latinize, $isCustom)) {
            return false;
        }
        $this->notify($info, $oldUrl, $slug);
        return true;
    }

    public function replaceTitleSlugMap($title, $namespace, $slug, $latinize = [], $isCustom = false) {
        $info = Utils::getTitleInfo($title, $namespace);
        if ($info && $this->repository->getData($info, true)) {
            return $this->updateTitleSlugMap($title, $namespace, $slug, $latinize, $isCustom);
        }
        return $this->addTitleSlugMap($title, $namespace, $slug, $latinize, $isCustom);
    }

    public function removeTitleSlugMap($title, $namespace = null) {
        $info = Utils::getTitleInfo($title, $namespace);
        $old = $info ? $this->repository->getData($info, true) : false;
        if (!$old) {
            return false;
        }
        $pageTitle = $this->titles->makeTitle($info->namespace, $info->title);
        $oldUrl = $this->urls->format($pageTitle, $old['url_slug']);
        if (!$this->repository->remove($info)) {
            return false;
        }
        $this->notify($info, $oldUrl, null);
        return true;
    }

    public function parseTitleToLatinize(Title $title, Language $language) {
        return $this->latinizer->parseTitleToLatinize($title, $language);
    }

    public function ensureAutomaticSlug(Title $title): bool {
        $info = Utils::getTitleInfo($title);
        if (!$info) {
            return false;
        }
        if ($this->repository->getData($info, true)) {
            return true;
        }
        $parsed = $this->parseTitleToLatinize($title, $title->getPageLanguage());
        if (!$parsed) {
            return false;
        }
        return $this->primary->doAtomicSection(__METHOD__, function () use ($title, $info, $parsed) {
            // Serialize automatic creators even though the mapping table has no unique key.
            $page = $this->primary->selectRow('page', ['page_id'], [
                'page_id' => $title->getId(), 'page_namespace' => $info->namespace,
                'page_title' => str_replace(' ', '_', $info->title)
            ], __METHOD__, ['FOR UPDATE']);
            if (!$page) {
                return false;
            }
            if ($this->repository->getData($info, true, true)) {
                return true;
            }
            return $this->addTitleSlugMap($title, null, $parsed['url_slug'], $parsed['latinize'], false)
                || $this->repository->getData($info, true) !== false;
        }, IDatabase::ATOMIC_CANCELABLE);
    }

    /** @internal Adapter for MediaWiki's Collation factory hook. */
    public function newCollation(): LatinizeCollation {
        return new LatinizeCollation($this->sortKeys, $this->latinizer, $this->cache);
    }

    private function notify(BaseTitleInfo $info, ?string $oldUrl, ?string $newSlug): void {
        if (!$this->hooks->isRegistered('LatinizeUrl::SlugChanged')) {
            return;
        }
        $title = $this->titles->makeTitle($info->namespace, $info->title);
        $this->hooks->run('LatinizeUrl::SlugChanged', [
            $title, $oldUrl, $newSlug === null ? null : $this->urls->format($title, $newSlug)
        ], ['abortable' => false]);
    }
}

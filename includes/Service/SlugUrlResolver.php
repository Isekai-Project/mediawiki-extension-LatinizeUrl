<?php

namespace LatinizeUrl\Service;

use LatinizeUrl\Utils;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;

class SlugUrlResolver {
    public function __construct(private SlugRepository $repository,
        private TitleFactory $titles, private WikiPageFactory $pages) {
    }

    public function resolve($slug, $namespace = null) {
        if ($slug instanceof Title) {
            $namespace = $namespace ?? $slug->getNamespace();
            $slug = $slug->getText();
        }
        $text = $this->repository->getTitleText($slug, $namespace ?? NS_MAIN);
        return $text ? ($this->titles->newFromText($text, $namespace ?? NS_MAIN) ?: false) : false;
    }

    public function resolveUrl($slugUrl, $namespace = null) {
        if ($slugUrl instanceof Title) {
            $namespace = $namespace ?? $slugUrl->getNamespace();
            $slugUrl = $slugUrl->getText();
        }
        if (preg_match('/^(\d+?)(?:-|~)/', $slugUrl, $matches)) {
            $page = $this->pages->newFromID((int)$matches[1]);
            if ($page) {
                return $page->getTitle();
            }
        }
        return $this->resolve($slugUrl, $namespace);
    }

    public function forTitle($title, $namespace = NS_MAIN) {
        if (is_string($title)) {
            $title = $this->titles->newFromText($title, $namespace);
        }
        if (!$title) {
            return false;
        }
        $info = Utils::getTitleInfo($title);
        $slug = $info ? $this->repository->getSlug($info) : false;
        return $slug ? $this->format($title, $slug) : false;
    }

    /** Shared formatting for queried URLs and old/new hook notifications. */
    public function format(Title $title, string $slug): string {
        if ($title->isMainPage()) {
            return $slug;
        }
        $id = $this->repository->getPageId($title->getNamespace(), $title->getDBkey());
        return $id . '-' . $slug;
    }
}

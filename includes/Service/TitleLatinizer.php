<?php

namespace LatinizeUrl\Service;

use Exception;
use LatinizeUrl\Utils;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Language\Language;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;

class TitleLatinizer {
    public function __construct(private SlugRepository $repository, private TitleFactory $titles,
        private HookContainer $hooks, private Language $language) {
    }

    public function forSortKey(string $text) {
        $convertor = $this->getConvertor();
        return $convertor ? Utils::wordListToUrl($convertor->parse($text)) : false;
    }
    private function getConvertor($language = null) {
        if ($language == null) {
            $language = $this->language;
        }

        if (is_callable([$language, 'getCode'])) {
            $language = $language->getCode();
        }

        $convertor = null;

        $this->hooks->run('LatinizeUrlGetConvertor', [
            $language,
            &$convertor,
        ]);

        return $convertor;
    }

    public function parseTitleToLatinize(Title $title, Language $language) {
        try {
            $convertor = $this->getConvertor($language);
            if (!$convertor) {
                wfLogWarning('No convertor found for language: ' . $language->getCode());
                return false;
            }

            if ($title->isSubpage()) {
                // 处理子页面，按照页面拆分，拼接父页面已有的Slug
                $titlePathList = explode('/', $title->getText());
                $titlePathLen = count($titlePathList);
                $unparsed = $title->getText();
                $baseSlug = false;
                for ($i = $titlePathLen - 2; $i >= 0; $i--) {
                    $titleSubPath = implode('/', array_slice($titlePathList, 0, $i + 1));
                    $baseTitle = $this->titles->newFromText($titleSubPath, $title->getNamespace());
                    $baseInfo = Utils::getTitleInfo($baseTitle);
                    $baseSlug = $baseInfo ? $this->repository->getSlug($baseInfo) : false;
                    if ($baseSlug) {
                        $unparsed = implode('/', array_slice($titlePathList, $i + 1));
                        break;
                    }
                }
                $parsed = $convertor->parse($unparsed);
                if ($parsed) {
                    $parsedSlug = Utils::wordListToUrl($parsed);
                    if ($baseSlug) {
                        return [
                            'url_slug' => $baseSlug . '/' . $parsedSlug,
                            'latinize' => array_merge([$baseSlug, '/'], $parsed),
                        ];
                    } else {
                        return [
                            'url_slug' => $parsedSlug,
                            'latinize' => $parsed,
                        ];
                    }
                } else {
                    return false;
                }
            } else {
                $parsed = $convertor->parse($title->getText());
                if ($parsed) {
                    $parsedSlug = Utils::wordListToUrl($parsed);
                    return [
                        'url_slug' => $parsedSlug,
                        'latinize' => $parsed,
                    ];
                } else {
                    return false;
                }
            }
        } catch (Exception $ex) {
            wfLogWarning('Cannot parse title to ascii: ' . $ex->getMessage());
            wfLogWarning($ex->getTraceAsString(), E_USER_ERROR);
            return false;
        }
    }
}

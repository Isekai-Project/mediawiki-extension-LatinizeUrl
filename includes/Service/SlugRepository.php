<?php

namespace LatinizeUrl\Service;

use LatinizeUrl\BaseTitleInfo;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

/** Internal mapping storage. Never publish uncommitted values to shared cache. */
class SlugRepository {
    private bool $readPrimary = false;

    public function __construct(private IDatabase $primary, private IReadableDatabase $replica,
        private WANObjectCache $cache) {
    }

    private function conditions(BaseTitleInfo $info): array {
        return ['title' => $info->title, 'namespace' => $info->namespace];
    }

    public function getData(BaseTitleInfo $info, bool $latest = false, bool $lock = false) {
        $db = $latest || $this->readPrimary ? $this->primary : $this->replica;
        $row = $db->selectRow('latinize_url_slug', '*', $this->conditions($info), __METHOD__, $lock ? ['FOR UPDATE'] : []);
        return $row ? (array)$row : false;
    }

    public function getSlug(BaseTitleInfo $info) {
        if ($this->readPrimary) {
            $data = $this->getData($info, true);
            return $data ? $data['url_slug'] : false;
        }
        return $this->cache->getWithSetCallback(
            $this->cache->makeKey('title2slug', $info->title, $info->namespace), 600,
            function ($old, &$ttl, &$setOpts) use ($info) {
                $setOpts += Database::getCacheSetOptions($this->replica);
                $data = $this->getData($info);
                return $data ? $data['url_slug'] : false;
            }
        );
    }

    public function getTitleText(string $slug, int $namespace) {
        $lookup = function ($db) use ($slug, $namespace) {
            $row = $db->selectRow('latinize_url_slug', ['title'],
                ['url_slug' => $slug, 'namespace' => $namespace], __METHOD__);
            return $row ? $row->title : false;
        };
        if ($this->readPrimary) {
            return $lookup($this->primary);
        }
        return $this->cache->getWithSetCallback(
            $this->cache->makeKey('slug2title', $slug, $namespace), 600,
            function ($old, &$ttl, &$setOpts) use ($lookup) {
                $setOpts += Database::getCacheSetOptions($this->replica);
                return $lookup($this->replica);
            }
        );
    }

    public function getPageId(int $namespace, string $dbKey): int {
        return (int)$this->primary->selectField('page', 'page_id',
            ['page_namespace' => $namespace, 'page_title' => $dbKey], __METHOD__);
    }

    public function add(BaseTitleInfo $info, string $slug, array $latinize, bool $custom): bool {
        if ($this->getData($info, true)) {
            wfLogWarning('[LatinizeUrl] Title slug map already exists: ' . $info->title);
            return false;
        }
        $this->primary->insert('latinize_url_slug', $this->conditions($info) + [
            'url_slug' => $slug, 'is_custom' => $custom ? 1 : 0,
            'latinized_words' => json_encode($latinize)
        ], __METHOD__);
        $this->changed($info, [ $slug ]);
        return true;
    }

    public function update(BaseTitleInfo $info, string $slug, array $latinize, bool $custom): bool {
        $old = $this->getData($info, true);
        if (!$old) {
            throw new \Exception('Title slug map not exists: ' . $info->title);
        }
        if ($old['url_slug'] == $slug) {
            return false;
        }
        $data = ['url_slug' => $slug, 'is_custom' => $custom ? 1 : 0];
        if ($latinize) {
            $data['latinized_words'] = json_encode($latinize);
        }
        $this->primary->update('latinize_url_slug', $data, ['id' => $old['id']], __METHOD__);
        $this->changed($info, [$old['url_slug'], $slug]);
        return true;
    }

    public function remove(BaseTitleInfo $info): bool {
        $old = $this->getData($info, true);
        if (!$old) {
            return false;
        }
        $this->primary->delete('latinize_url_slug', $this->conditions($info), __METHOD__);
        $this->changed($info, [$old['url_slug']]);
        return true;
    }

    private function changed(BaseTitleInfo $info, array $slugs): void {
        // Read our writes directly, including before commit; no shared cache backfill.
        $this->readPrimary = true;
        $keys = [
            $this->cache->makeKey('title2slug', $info->title, $info->namespace),
            $this->cache->makeKey('title2slugurl', $info->title, $info->namespace)
        ];
        foreach (array_unique($slugs) as $slug) {
            $keys[] = $this->cache->makeKey('slug2title', $slug, $info->namespace);
        }
        $invalidate = function () use ($keys) {
            foreach ($keys as $key) {
                $this->cache->delete($key);
            }
        };
        $invalidate();
        // Invalidate again after commit: a reader may have raced the uncommitted write.
        $this->primary->onTransactionCommitOrIdle($invalidate, __METHOD__);
    }
}

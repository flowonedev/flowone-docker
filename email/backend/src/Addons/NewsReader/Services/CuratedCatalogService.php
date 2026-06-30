<?php

namespace Webmail\Addons\NewsReader\Services;

class CuratedCatalogService
{
    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function getGrouped(): array
    {
        $empty = ['HU' => [], 'EN' => [], 'US' => [], 'VIDEO' => []];
        $path = dirname(__DIR__) . '/data/curated_feeds.json';
        if (!is_readable($path)) {
            return $empty;
        }
        $json = file_get_contents($path);
        $data = json_decode($json ?: '{}', true);
        if (!is_array($data)) {
            return $empty;
        }

        return [
            'HU' => $data['HU'] ?? [],
            'EN' => $data['EN'] ?? [],
            'US' => $data['US'] ?? [],
            'VIDEO' => $data['VIDEO'] ?? [],
        ];
    }
}

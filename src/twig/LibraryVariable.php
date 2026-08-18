<?php

declare(strict_types=1);

namespace justinholtweb\library\twig;

use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use justinholtweb\library\models\Collection;
use justinholtweb\library\Plugin;

/**
 * `craft.library` — the front-end and template-facing API.
 */
class LibraryVariable
{
    /** @return Collection[] */
    public function collections(): array
    {
        return Plugin::getInstance()->collections->getTree();
    }

    public function collection(string $handle): ?Collection
    {
        return Plugin::getInstance()->collections->getCollectionByHandle($handle);
    }

    /**
     * An asset query narrowed to a collection, ready to be refined further.
     *
     * Nested collections are included, so `craft.library.assets('brand')` returns everything filed
     * anywhere under Brand — the same thing the collection means everywhere else in the plugin.
     *
     * ```twig
     * {% for image in craft.library.assets('logos').kind('image').limit(12).all() %}
     * ```
     */
    public function assets(string|Collection|array $collections): AssetQuery
    {
        $plugin = Plugin::getInstance();
        $ids = [];

        foreach (is_array($collections) ? $collections : [$collections] as $value) {
            $collection = $value instanceof Collection
                ? $value
                : $plugin->collections->getCollectionByHandle((string)$value);

            if ($collection === null || $collection->id === null) {
                continue;
            }

            foreach ($plugin->collections->getIdWithDescendants($collection->id) as $id) {
                $ids[$id] = true;
            }
        }

        $assetIds = $ids ? $plugin->membership->getAssetIdsInCollections(array_keys($ids)) : [];

        // `false`, not an empty array: in Craft's param syntax an empty array is no filter at all,
        // so an unknown handle would quietly return the whole library instead of nothing.
        return Asset::find()->id($assetIds ?: false);
    }

    /** @return Collection[] The collections an asset belongs to. */
    public function collectionsFor(Asset|int $asset): array
    {
        $id = $asset instanceof Asset ? (int)$asset->id : $asset;

        return Plugin::getInstance()->membership->getCollectionsForAsset($id);
    }

    /** Whether an asset is filed in a given collection. */
    public function isIn(Asset|int $asset, string|Collection $collection): bool
    {
        $plugin = Plugin::getInstance();

        $collection = $collection instanceof Collection
            ? $collection
            : $plugin->collections->getCollectionByHandle((string)$collection);

        if ($collection === null || $collection->id === null) {
            return false;
        }

        $assetId = $asset instanceof Asset ? (int)$asset->id : $asset;
        $wanted = array_flip($plugin->collections->getIdWithDescendants($collection->id));

        foreach ($plugin->membership->getCollectionIdsForAsset($assetId) as $id) {
            if (isset($wanted[$id])) {
                return true;
            }
        }

        return false;
    }

    /** How many places an asset is used. Pro; returns null on Lite. */
    public function usageCount(Asset|int $asset): ?int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            return null;
        }

        return $plugin->usage->getCountForAsset($asset instanceof Asset ? (int)$asset->id : $asset);
    }
}

<?php

declare(strict_types=1);

namespace justinholtweb\library\services;

use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\helpers\Db;
use justinholtweb\library\models\Collection;
use justinholtweb\library\Plugin;
use justinholtweb\library\records\CollectionAssetRecord;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Membership — which assets are in which collections.
 *
 * Everything here works in bulk. A media library is the one screen where "do this to 400 things"
 * is the normal request, so there is no per-asset write path that a loop could turn into 400
 * queries.
 */
class Membership extends Component
{
    /**
     * @var array<int,Collection[]>|null Per-request cache, keyed by asset ID.
     * @see primeFor()
     */
    private array $cache = [];

    /**
     * Adds assets to collections, leaving any existing membership alone.
     *
     * @param int[] $assetIds
     * @param int[] $collectionIds
     * @return int Rows added.
     */
    public function assign(array $assetIds, array $collectionIds): int
    {
        $assetIds = $this->cleanIds($assetIds);
        $collectionIds = $this->cleanIds($collectionIds);

        if (!$assetIds || !$collectionIds) {
            return 0;
        }

        $existing = $this->existingPairs($assetIds, $collectionIds);
        $rows = [];

        foreach ($collectionIds as $collectionId) {
            foreach ($assetIds as $assetId) {
                if (isset($existing["$collectionId:$assetId"])) {
                    continue;
                }

                $rows[] = [$collectionId, $assetId, null];
            }
        }

        if (!$rows) {
            return 0;
        }

        // One INSERT for the lot; Craft's batchInsert fills in dateCreated/dateUpdated/uid.
        try {
            $inserted = Db::batchInsert(CollectionAssetRecord::TABLE, ['collectionId', 'assetId', 'sortOrder'], $rows);
        } catch (IntegrityException) {
            // The unique index — not the read above — is the authority on what already exists. Two
            // editors filing the same asset in the same instant both saw "not there yet", and the
            // loser of that race must not take a 500 with it. Only then is it worth a query a row.
            $inserted = 0;

            foreach ($rows as [$collectionId, $assetId, $sortOrder]) {
                $inserted += Db::upsert(CollectionAssetRecord::TABLE, [
                    'collectionId' => $collectionId,
                    'assetId' => $assetId,
                ], [
                    'sortOrder' => $sortOrder,
                ]);
            }
        }

        $this->clearCache();
        Plugin::getInstance()->collections->clearCaches();

        return $inserted;
    }

    /**
     * @param int[] $assetIds
     * @param int[] $collectionIds
     * @return int Rows removed.
     */
    public function remove(array $assetIds, array $collectionIds): int
    {
        $assetIds = $this->cleanIds($assetIds);
        $collectionIds = $this->cleanIds($collectionIds);

        if (!$assetIds || !$collectionIds) {
            return 0;
        }

        $removed = Db::delete(CollectionAssetRecord::TABLE, [
            'collectionId' => $collectionIds,
            'assetId' => $assetIds,
        ]);

        $this->clearCache();
        Plugin::getInstance()->collections->clearCaches();

        return $removed;
    }

    /**
     * Replaces the given assets' membership outright — used by the asset edit screen, where the
     * chip list posted back is the whole truth for that asset.
     *
     * @param int[] $assetIds
     * @param int[] $collectionIds
     */
    public function set(array $assetIds, array $collectionIds): void
    {
        $assetIds = $this->cleanIds($assetIds);

        if (!$assetIds) {
            return;
        }

        Db::delete(CollectionAssetRecord::TABLE, ['assetId' => $assetIds]);

        $this->clearCache();
        Plugin::getInstance()->collections->clearCaches();

        $this->assign($assetIds, $collectionIds);
    }

    /** Drops every membership row for a collection, without deleting the collection. */
    public function clearCollection(int $collectionId): int
    {
        $removed = Db::delete(CollectionAssetRecord::TABLE, ['collectionId' => $collectionId]);

        $this->clearCache();
        Plugin::getInstance()->collections->clearCaches();

        return $removed;
    }

    /**
     * Loads collections for a set of assets into the per-request cache.
     *
     * The asset index renders the Collections column one row at a time, so without this the column
     * costs a query per row. Craft fires `EVENT_PREP_QUERY_FOR_TABLE_ATTRIBUTE` with the page's
     * query still unexecuted, which is where this gets called from.
     *
     * @param int[] $assetIds
     */
    public function primeFor(array $assetIds): void
    {
        $missing = array_values(array_diff($this->cleanIds($assetIds), array_keys($this->cache)));

        if (!$missing) {
            return;
        }

        foreach ($this->getCollectionsForAssets($missing) as $assetId => $collections) {
            $this->cache[$assetId] = $collections;
        }
    }

    /** @return int[] Collection IDs the asset belongs to. */
    public function getCollectionIdsForAsset(int $assetId): array
    {
        if (isset($this->cache[$assetId])) {
            return array_map(fn(Collection $c) => (int)$c->id, $this->cache[$assetId]);
        }

        return array_map('intval', (new Query())
            ->select(['collectionId'])
            ->from(CollectionAssetRecord::TABLE)
            ->where(['assetId' => $assetId])
            ->column());
    }

    /** @return Collection[] */
    public function getCollectionsForAsset(int $assetId): array
    {
        return $this->cache[$assetId]
            ?? Plugin::getInstance()->collections->getCollectionsByIds($this->getCollectionIdsForAsset($assetId));
    }

    /** Drops the per-request cache. Called by every write path here. */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Collections for many assets at once, keyed by asset ID.
     *
     * The element index calls this once per page of results; without it the Collections column
     * would be one query per row.
     *
     * @param int[] $assetIds
     * @return array<int,Collection[]>
     */
    public function getCollectionsForAssets(array $assetIds): array
    {
        $assetIds = $this->cleanIds($assetIds);

        if (!$assetIds) {
            return [];
        }

        $rows = (new Query())
            ->select(['assetId', 'collectionId'])
            ->from(CollectionAssetRecord::TABLE)
            ->where(['assetId' => $assetIds])
            ->all();

        $collections = Plugin::getInstance()->collections->getAllCollections();
        $map = array_fill_keys($assetIds, []);

        foreach ($rows as $row) {
            $collection = $collections[(int)$row['collectionId']] ?? null;

            if ($collection !== null) {
                $map[(int)$row['assetId']][] = $collection;
            }
        }

        foreach ($map as &$list) {
            usort($list, fn(Collection $a, Collection $b) => [$a->sortOrder, $a->name] <=> [$b->sortOrder, $b->name]);
        }

        return $map;
    }

    /**
     * Asset IDs in the given collections.
     *
     * @param int[] $collectionIds
     * @return int[]
     */
    public function getAssetIdsInCollections(array $collectionIds): array
    {
        $collectionIds = $this->cleanIds($collectionIds);

        if (!$collectionIds) {
            return [];
        }

        return array_map('intval', (new Query())
            ->select(['assetId'])
            ->distinct()
            ->from(CollectionAssetRecord::TABLE)
            ->where(['collectionId' => $collectionIds])
            ->column());
    }

    /**
     * Every collection's asset IDs in one query, keyed by collection ID.
     *
     * The asset index builds a source per collection on every render — and so does every asset field
     * selector modal — so asking per collection is N queries on the hottest path in the plugin.
     * Trashed assets are left out here rather than being handed to a source that would silently
     * not match them.
     *
     * @return array<int,int[]>
     */
    public function getAllMemberships(): array
    {
        $rows = (new Query())
            ->select(['ca.collectionId', 'ca.assetId'])
            ->from(['ca' => CollectionAssetRecord::TABLE])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[ca.assetId]]')
            ->where(['e.dateDeleted' => null])
            ->all();

        $map = [];

        foreach ($rows as $row) {
            $map[(int)$row['collectionId']][] = (int)$row['assetId'];
        }

        return $map;
    }

    /**
     * Moves every asset from one collection into another, then optionally deletes the source.
     * This is the "I made two collections for the same thing" repair.
     */
    public function mergeCollections(int $fromId, int $intoId, bool $deleteSource = true): int
    {
        if ($fromId === $intoId) {
            return 0;
        }

        $assetIds = $this->getAssetIdsInCollections([$fromId]);
        $moved = $this->assign($assetIds, [$intoId]);

        if ($deleteSource) {
            Plugin::getInstance()->collections->deleteCollectionById($fromId);
        } else {
            $this->clearCollection($fromId);
        }

        return $moved;
    }

    /**
     * Deletes membership rows pointing at assets that no longer exist.
     *
     * The foreign key handles hard deletes; this catches rows left behind if a volume's rows were
     * removed out from under Craft, and gives garbage collection something to call.
     */
    public function pruneOrphans(): int
    {
        $orphanIds = (new Query())
            ->select(['ca.id'])
            ->from(['ca' => CollectionAssetRecord::TABLE])
            ->leftJoin(['a' => CraftTable::ASSETS], '[[a.id]] = [[ca.assetId]]')
            ->where(['a.id' => null])
            ->column();

        if (!$orphanIds) {
            return 0;
        }

        $deleted = Db::delete(CollectionAssetRecord::TABLE, ['id' => $orphanIds]);

        $this->clearCache();
        Plugin::getInstance()->collections->clearCaches();

        return $deleted;
    }

    /** @return array<string,true> Keyed "collectionId:assetId". */
    private function existingPairs(array $assetIds, array $collectionIds): array
    {
        $rows = (new Query())
            ->select(['collectionId', 'assetId'])
            ->from(CollectionAssetRecord::TABLE)
            ->where(['collectionId' => $collectionIds, 'assetId' => $assetIds])
            ->all();

        $pairs = [];

        foreach ($rows as $row) {
            $pairs[$row['collectionId'] . ':' . $row['assetId']] = true;
        }

        return $pairs;
    }

    /** @return int[] Unique, positive, integer-cast IDs. */
    private function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn(int $id) => $id > 0)));
    }
}

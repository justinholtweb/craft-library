<?php

declare(strict_types=1);

namespace justinholtweb\library\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use justinholtweb\library\models\Collection;
use justinholtweb\library\records\CollectionAssetRecord;
use justinholtweb\library\records\CollectionRecord;
use yii\base\Component;

/**
 * Collections — the taxonomy itself. CRUD, the tree, and asset counts.
 */
class Collections extends Component
{
    /** @var Collection[]|null Memoised for the request; every source, chip and menu asks for these. */
    private ?array $all = null;

    /** @var array<int,int>|null */
    private ?array $counts = null;

    /** @var array<int,int[]>|null Parent ID (0 for top level) => child IDs. */
    private ?array $byParent = null;

    /**
     * Every collection, in tree order (parents followed by their children), each with `children`
     * populated.
     *
     * @return Collection[]
     */
    public function getTree(): array
    {
        return $this->buildBranch(0, []);
    }

    /**
     * @param int[] $seen Parent IDs already walked, so a cycle in the data cannot hang the request.
     * @return Collection[]
     */
    private function buildBranch(int $parentId, array $seen): array
    {
        if (isset($seen[$parentId])) {
            return [];
        }
        $seen[$parentId] = true;

        $all = $this->getAllCollections();
        $branch = [];

        foreach ($this->childIds($parentId) as $id) {
            if (!isset($all[$id])) {
                continue;
            }

            // A fresh instance per node: the memoised set is shared with every other caller, and
            // must not end up carrying one tree walk's `children` into the next.
            $node = new Collection($all[$id]->getAttributes());
            $node->children = $this->buildBranch($id, $seen);
            $branch[] = $node;
        }

        return $branch;
    }

    /** @return int[] */
    private function childIds(int $parentId): array
    {
        if ($this->byParent === null) {
            $this->byParent = [];

            foreach ($this->getAllCollections() as $collection) {
                $this->byParent[$collection->parentId ?? 0][] = (int)$collection->id;
            }
        }

        return $this->byParent[$parentId] ?? [];
    }

    /** @return Collection[] Keyed by ID, ordered by sortOrder then name. */
    public function getAllCollections(): array
    {
        if ($this->all === null) {
            $this->all = [];

            $rows = (new Query())
                ->select(['id', 'name', 'handle', 'parentId', 'description', 'color', 'sortOrder', 'uid'])
                ->from(CollectionRecord::TABLE)
                ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
                ->all();

            foreach ($rows as $row) {
                $row['id'] = (int)$row['id'];
                $row['parentId'] = $row['parentId'] !== null ? (int)$row['parentId'] : null;
                $row['sortOrder'] = (int)$row['sortOrder'];
                $this->all[$row['id']] = new Collection($row);
            }
        }

        return $this->all;
    }

    public function getCollectionById(int $id): ?Collection
    {
        return $this->getAllCollections()[$id] ?? null;
    }

    public function getCollectionByHandle(string $handle): ?Collection
    {
        foreach ($this->getAllCollections() as $collection) {
            if ($collection->handle === $handle) {
                return $collection;
            }
        }

        return null;
    }

    public function getCollectionByUid(string $uid): ?Collection
    {
        foreach ($this->getAllCollections() as $collection) {
            if ($collection->uid === $uid) {
                return $collection;
            }
        }

        return null;
    }

    /** @param int[] $ids @return Collection[] */
    public function getCollectionsByIds(array $ids): array
    {
        $all = $this->getAllCollections();

        return array_values(array_filter(
            array_map(fn($id) => $all[(int)$id] ?? null, $ids),
        ));
    }

    public function saveCollection(Collection $collection, bool $runValidation = true): bool
    {
        $collection->suggestHandle();

        if ($runValidation && !$collection->validate()) {
            return false;
        }

        $record = $collection->id !== null
            ? CollectionRecord::findOne($collection->id)
            : new CollectionRecord();

        if ($record === null) {
            return false;
        }

        $record->name = $collection->name;
        $record->handle = $collection->handle;
        $record->parentId = $collection->parentId;
        $record->description = $collection->description;
        $record->color = $collection->color;
        $record->sortOrder = $collection->sortOrder;
        $record->save(false);

        $collection->id = (int)$record->id;
        $collection->uid = $record->uid;

        $this->clearCaches();

        return true;
    }

    public function deleteCollectionById(int $id): bool
    {
        // Children are promoted to the top level by the `SET NULL` foreign key, and membership
        // rows go with the collection by `CASCADE`. Nothing to unwind by hand.
        $affected = Db::delete(CollectionRecord::TABLE, ['id' => $id]);

        $this->clearCaches();

        return $affected > 0;
    }

    /** Applies a new order from the collections index, top level and nesting together. */
    public function reorder(array $ids, array $parents = []): void
    {
        foreach (array_values($ids) as $i => $id) {
            $id = (int)$id;
            $data = ['sortOrder' => $i];

            if (array_key_exists($id, $parents)) {
                $data['parentId'] = $parents[$id] !== null ? (int)$parents[$id] : null;
            }

            Db::update(CollectionRecord::TABLE, $data, ['id' => $id], updateTimestamp: true);
        }

        $this->clearCaches();
    }

    /**
     * Asset count per collection ID, counting only assets that still exist and are not in the
     * trash — a soft-deleted asset keeps its membership row, and a badge that counts it is a lie.
     *
     * @return array<int,int>
     */
    public function getAssetCounts(): array
    {
        if ($this->counts === null) {
            $rows = (new Query())
                ->select(['ca.collectionId', 'total' => 'COUNT(*)'])
                ->from(['ca' => CollectionAssetRecord::TABLE])
                ->innerJoin(['e' => \craft\db\Table::ELEMENTS], '[[e.id]] = [[ca.assetId]]')
                ->where(['e.dateDeleted' => null])
                ->groupBy(['ca.collectionId'])
                ->all();

            $this->counts = [];

            foreach ($rows as $row) {
                $this->counts[(int)$row['collectionId']] = (int)$row['total'];
            }
        }

        return $this->counts;
    }

    /** Count for one collection, including its descendants — that is what a tree badge means. */
    public function getAssetCount(int $collectionId, bool $includeDescendants = true): int
    {
        $counts = $this->getAssetCounts();
        $total = $counts[$collectionId] ?? 0;

        if ($includeDescendants) {
            foreach ($this->getDescendantIds($collectionId) as $id) {
                $total += $counts[$id] ?? 0;
            }
        }

        return $total;
    }

    /** @return int[] */
    public function getDescendantIds(int $collectionId): array
    {
        $ids = [];
        $queue = $this->childIds($collectionId);

        while ($queue) {
            $id = array_shift($queue);

            // Also the cycle guard: a row whose parent chain loops is visited once and dropped.
            if (isset($ids[$id])) {
                continue;
            }

            $ids[$id] = true;
            array_push($queue, ...$this->childIds($id));
        }

        return array_keys($ids);
    }

    /** A collection plus its descendants, for "show me everything filed under Brand". */
    public function getIdWithDescendants(int $collectionId): array
    {
        return [$collectionId, ...$this->getDescendantIds($collectionId)];
    }

    public function clearCaches(): void
    {
        $this->all = null;
        $this->counts = null;
        $this->byParent = null;
    }
}

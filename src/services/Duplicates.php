<?php

declare(strict_types=1);

namespace justinholtweb\library\services;

use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\helpers\Db;
use justinholtweb\library\Plugin;
use justinholtweb\library\records\HashRecord;
use justinholtweb\library\records\UsageRecord;
use Throwable;
use yii\base\Component;

/**
 * Duplicates — finds byte-identical files, and repoints content off a copy before it is deleted.
 *
 * Size first, hash second. Reading a file means pulling it through the filesystem adapter, which
 * for a remote volume is a download, so nothing is hashed until its size has a twin.
 */
class Duplicates extends Component
{
    /**
     * Hashes assets that need it and returns how many were done.
     *
     * Only assets whose size matches at least one other asset are considered, and only those whose
     * stored hash is missing or was taken at a different size.
     */
    public function hashBatch(?int $limit = null): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $limit ??= $settings->hashBatchSize;
        $maxBytes = $settings->maxHashFileSizeMb * 1024 * 1024;

        $assetIds = $this->needsHashingQuery($maxBytes)->limit($limit)->column();

        if (!$assetIds) {
            return 0;
        }

        $assets = Asset::find()->id(array_map('intval', $assetIds))->status(null)->all();
        $done = 0;

        foreach ($assets as $asset) {
            if ($this->hashAsset($asset)) {
                $done++;
            }
        }

        return $done;
    }

    /** How many assets are still waiting to be hashed. Drives the progress line on the audit page. */
    public function getPendingHashCount(): int
    {
        $maxBytes = Plugin::getInstance()->getSettings()->maxHashFileSizeMb * 1024 * 1024;

        return (int)$this->needsHashingQuery($maxBytes)->count();
    }

    /**
     * Assets that share a file size with another asset but have no current hash.
     *
     * Assets bigger than the cap are excluded, not silently skipped forever: the audit screen
     * reports them separately so a run that ignored a 4 GB video says so.
     */
    private function needsHashingQuery(int $maxBytes): Query
    {
        $excluded = Plugin::getInstance()->getSettings()->getExcludedVolumeIds();

        $sharedSizes = (new Query())
            ->select(['size'])
            ->from(['a2' => CraftTable::ASSETS])
            ->innerJoin(['e2' => CraftTable::ELEMENTS], '[[e2.id]] = [[a2.id]]')
            ->where(['e2.dateDeleted' => null])
            ->andWhere(['not', ['a2.size' => null]])
            ->andWhere(['>', 'a2.size', 0])
            ->groupBy(['a2.size'])
            ->having(['>', 'COUNT(*)', 1]);

        $query = (new Query())
            ->select(['a.id'])
            ->from(['a' => CraftTable::ASSETS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[a.id]]')
            ->leftJoin(['h' => HashRecord::TABLE], '[[h.assetId]] = [[a.id]]')
            ->where(['e.dateDeleted' => null])
            ->andWhere(['<=', 'a.size', $maxBytes])
            ->andWhere(['a.size' => $sharedSizes])
            ->andWhere([
                'or',
                // Never hashed: no row at all.
                ['h.assetId' => null],
                // The file was replaced since it was hashed, so the stored hash is stale.
                '[[h.size]] <> [[a.size]]',
            ]);

        // A row with a null hash is one that was *tried and could not be read*, and it is
        // deliberately not re-selected. Treating it as "needs hashing" made every batch pick the
        // same unreadable files, fail on all of them, and return 0 — which is the signal both the
        // console loop and the browser loop use to stop. A handful of broken files at the front of
        // an unordered result set therefore halted hashing for the entire library, permanently,
        // while the pending count sat unchanged. `library/audit/hash --all` after fixing the file,
        // or clearHashes(), is how a genuine retry happens.

        if ($excluded) {
            $query->andWhere(['not', ['a.volumeId' => $excluded]]);
        }

        return $query;
    }

    /** Reads an asset's bytes and stores its hash. Returns false if the file could not be read. */
    public function hashAsset(Asset $asset): bool
    {
        if ($asset->id === null) {
            return false;
        }

        $stream = null;

        try {
            $stream = $asset->getStream();
            $context = hash_init('sha1');

            // Streamed rather than read whole: a media library is exactly where the 2 GB video
            // lives, and file_get_contents on it takes the process down with it.
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    break;
                }

                hash_update($context, $chunk);
            }

            $hash = hash_final($context);
        } catch (Throwable $e) {
            Craft::warning("Library could not hash asset {$asset->id}: {$e->getMessage()}", Plugin::LOG_CATEGORY);

            // Recorded with a null hash so the same unreadable file is not retried on every run.
            //
            // Every column goes in the *insert* half as well as the update half: Db::upsert() only
            // inserts the columns it is given as `$insertColumns`, and uses `$updateColumns` solely
            // for the ON DUPLICATE KEY branch. Passing the key alone inserts a row with null
            // everywhere else and updates nothing, which is silent — the row exists, so nothing
            // retries it, and every hash reads back as null.
            Db::upsert(HashRecord::TABLE, [
                'assetId' => $asset->id,
                'size' => $asset->size,
                'hash' => null,
            ], [
                'size' => $asset->size,
                'hash' => null,
            ]);

            return false;
        } finally {
            // A batch of a hundred unreadable remote files would otherwise leak a hundred handles
            // and hit the open-file limit before the run finished.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        Db::upsert(HashRecord::TABLE, [
            'assetId' => $asset->id,
            'size' => $asset->size,
            'hash' => $hash,
        ], [
            'size' => $asset->size,
            'hash' => $hash,
        ]);

        return true;
    }

    /**
     * Duplicate groups, newest-first within each group.
     *
     * @return array<int,array{hash:string,size:int,assets:Asset[]}>
     */
    public function getDuplicateGroups(int $limit = 100): array
    {
        $rows = (new Query())
            ->select(['h.hash', 'h.size', 'total' => 'COUNT(*)'])
            ->from(['h' => HashRecord::TABLE])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[h.assetId]]')
            ->where(['e.dateDeleted' => null])
            ->andWhere(['not', ['h.hash' => null]])
            ->groupBy(['h.hash', 'h.size'])
            ->having(['>', 'COUNT(*)', 1])
            ->orderBy(['COUNT(*)' => SORT_DESC, 'h.size' => SORT_DESC])
            ->limit($limit)
            ->all();

        if (!$rows) {
            return [];
        }

        $hashes = array_column($rows, 'hash');

        $assetIdsByHash = [];

        foreach ((new Query())
            ->select(['assetId', 'hash'])
            ->from(HashRecord::TABLE)
            ->where(['hash' => $hashes])
            ->all() as $row) {
            $assetIdsByHash[$row['hash']][] = (int)$row['assetId'];
        }

        // One query for every asset in every group, rather than one per group.
        $allAssets = Asset::find()
            ->id(array_merge(...array_values($assetIdsByHash)))
            ->status(null)
            ->indexBy('id')
            ->all();

        $groups = [];

        foreach ($rows as $row) {
            $assets = array_values(array_filter(array_map(
                fn(int $id) => $allAssets[$id] ?? null,
                $assetIdsByHash[$row['hash']] ?? [],
            )));

            if (count($assets) < 2) {
                continue;
            }

            // Oldest first: the original is the one everything else should be repointed at.
            usort($assets, fn(Asset $a, Asset $b) => [$a->dateCreated, $a->id] <=> [$b->dateCreated, $b->id]);

            $groups[] = [
                'hash' => (string)$row['hash'],
                'size' => (int)$row['size'],
                'assets' => $assets,
            ];
        }

        return $groups;
    }

    public function getDuplicateCount(): int
    {
        // One COUNT per hash group, then copies rather than groups: three identical files are two
        // deletable copies, and that is the number worth putting on a badge.
        $groupSizes = array_map('intval', (new Query())
            ->select(['total' => 'COUNT(*)'])
            ->from(['h' => HashRecord::TABLE])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[h.assetId]]')
            ->where(['e.dateDeleted' => null])
            ->andWhere(['not', ['h.hash' => null]])
            ->groupBy(['h.hash'])
            ->having(['>', 'COUNT(*)', 1])
            ->column());

        return array_sum($groupSizes) - count($groupSizes);
    }

    /**
     * @return int[] Asset IDs that are a copy of something older.
     *
     * Deliberately one flat query with the grouping done in PHP: no `HAVING`, no window function,
     * and no loading of the assets themselves. A hash with a single asset contributes its keeper
     * and nothing else, so lone files fall out on their own.
     */
    public function getDuplicateAssetIds(): array
    {
        $rows = (new Query())
            ->select(['h.assetId', 'h.hash'])
            ->from(['h' => HashRecord::TABLE])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[h.assetId]]')
            ->where(['e.dateDeleted' => null])
            ->andWhere(['not', ['h.hash' => null]])
            ->orderBy(['h.hash' => SORT_ASC, 'e.dateCreated' => SORT_ASC, 'h.assetId' => SORT_ASC])
            ->all();

        $ids = [];
        $keeperSeen = [];

        foreach ($rows as $row) {
            $hash = (string)$row['hash'];

            // First row for a hash is the original; everything after it is a copy.
            if (!isset($keeperSeen[$hash])) {
                $keeperSeen[$hash] = true;
                continue;
            }

            $ids[] = (int)$row['assetId'];
        }

        return $ids;
    }

    /**
     * Repoints every relation on `$copy` at `$original`, so the copy can be deleted without
     * blanking the fields that pointed at it.
     *
     * Reference tags in text are reported, not rewritten — silently editing somebody's copy is not
     * this plugin's call to make.
     *
     * @return array{relations:int,refs:int}
     */
    public function repoint(Asset $copy, Asset $original): array
    {
        if ($copy->id === null || $original->id === null || $copy->id === $original->id) {
            return ['relations' => 0, 'refs' => 0];
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            // Any relation that would collide with an existing one after the switch has to go
            // first: (fieldId, sourceId, sourceSiteId, targetId) is unique, and an entry that
            // already relates to both files would otherwise fail the update.
            $colliding = (new Query())
                ->select(['dupe.id'])
                ->from(['dupe' => CraftTable::RELATIONS])
                ->innerJoin(['keep' => CraftTable::RELATIONS], [
                    'and',
                    '[[keep.fieldId]] = [[dupe.fieldId]]',
                    '[[keep.sourceId]] = [[dupe.sourceId]]',
                    ['or',
                        '[[keep.sourceSiteId]] = [[dupe.sourceSiteId]]',
                        ['and', ['keep.sourceSiteId' => null], ['dupe.sourceSiteId' => null]],
                    ],
                ])
                ->where(['dupe.targetId' => $copy->id, 'keep.targetId' => $original->id])
                ->column();

            if ($colliding) {
                Db::delete(CraftTable::RELATIONS, ['id' => $colliding]);
            }

            $relations = Db::update(
                CraftTable::RELATIONS,
                ['targetId' => $original->id],
                ['targetId' => $copy->id],
                updateTimestamp: false,
            );

            // The same collision the relations block above guards against, on
            // `library_usage`'s unique (assetId, elementId, source). An element whose body
            // reference-tags *both* files has a row for each, and switching the copy's row over
            // would duplicate the original's. Without this the merge throws 1062, the transaction
            // rolls back, and that duplicate pair can never be resolved.
            $collidingRefs = (new Query())
                ->select(['dupe.id'])
                ->from(['dupe' => UsageRecord::TABLE])
                ->innerJoin(['keep' => UsageRecord::TABLE], [
                    'and',
                    '[[keep.elementId]] = [[dupe.elementId]]',
                    '[[keep.source]] = [[dupe.source]]',
                ])
                ->where(['dupe.assetId' => $copy->id, 'keep.assetId' => $original->id])
                ->column();

            if ($collidingRefs) {
                Db::delete(UsageRecord::TABLE, ['id' => $collidingRefs]);
            }

            $refs = Db::update(
                UsageRecord::TABLE,
                ['assetId' => $original->id],
                ['assetId' => $copy->id],
                updateTimestamp: false,
            );

            // The copy's collection memberships are worth keeping — whoever filed it did so on
            // purpose, and the original inherits them.
            Plugin::getInstance()->membership->assign(
                [$original->id],
                Plugin::getInstance()->membership->getCollectionIdsForAsset($copy->id),
            );

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Craft caches relation lookups per element; the entries just repointed have to forget.
        Craft::$app->getElements()->invalidateCachesForElement($original);
        Craft::$app->getElements()->invalidateCachesForElement($copy);

        return ['relations' => $relations, 'refs' => $refs];
    }

    public function clearHashes(): int
    {
        return Db::delete(HashRecord::TABLE);
    }
}

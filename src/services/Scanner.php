<?php

declare(strict_types=1);

namespace justinholtweb\library\services;

use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\helpers\Db;
use justinholtweb\library\Plugin;
use justinholtweb\library\records\UsageRecord;
use yii\base\Component;

/**
 * Scanner — finds asset references that Craft's relations table does not know about.
 *
 * Craft records a relation whenever an asset is picked in a field, including from CKEditor and
 * Redactor. What it cannot record is a reference somebody typed: `{asset:412:url}` pasted into a
 * plain text field, or markup carried in from an old site. Nothing indexes those, so the only way
 * to know is to read the content, which is what this does.
 *
 * Content lives in `elements_sites.content` as JSON, and nested elements — Matrix blocks and the
 * like — have their own rows there. So one pass over that table sees everything, at any depth,
 * without knowing anything about field types.
 */
class Scanner extends Component
{
    /**
     * Matches Craft reference tags pointing at an asset, by ID or by UID.
     *
     * `{asset:412:url}` and `{asset:9f8c…:url}` both count; a volume-and-path reference
     * (`{asset:images/logo.png:url}`) is deliberately not matched, because resolving it means
     * asking the filesystem and a scan cannot afford that per row.
     */
    private const REF_PATTERN = '/\{asset:([0-9]+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})[:@}]/i';

    /** Legacy markup that carries an asset ID without a reference tag. */
    private const ATTR_PATTERN = '/data-(?:asset|assetid|asset-id)=\\\\?["\']?([0-9]+)/i';

    /**
     * Reads every content row and rebuilds the reference-tag half of the usage table.
     *
     * @param callable|null $progress Called with (processed, total) after each batch.
     * @return array{rows:int,elements:int,assets:int,scanned:int}
     */
    public function scan(int $batchSize = 500, ?callable $progress = null): array
    {
        $total = (int)$this->candidateQuery()->count();
        $scanned = 0;
        $found = [];

        // Ordered and paged by primary key rather than by OFFSET: the rows being read are not being
        // written, but a growing OFFSET over a large table gets slower every batch for no reason.
        $lastId = 0;

        while (true) {
            $rows = $this->candidateQuery()
                ->andWhere(['>', 'es.id', $lastId])
                ->orderBy(['es.id' => SORT_ASC])
                ->limit($batchSize)
                ->all();

            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $scanned++;

                foreach ($this->extractAssetRefs((string)$row['content']) as $ref) {
                    $found[(int)$row['elementId']][$ref] = [
                        'elementType' => (string)$row['type'],
                        'siteId' => (int)$row['siteId'],
                    ];
                }
            }

            if ($progress !== null) {
                $progress($scanned, $total);
            }
        }

        // The delete and the rebuild happen together, at the end, in one transaction — not before
        // the read loop. Clearing first meant that a scan which timed out, lost its connection or
        // hit one bad row left the site with *no* recorded reference-tag usage at all, so every
        // asset referenced only by a typed tag showed up as unused. That is the list editors bulk
        // delete from, which makes a half-finished scan actively dangerous rather than merely
        // incomplete.
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            Plugin::getInstance()->usage->clearRefUsage();
            $result = $this->record($found);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $result + ['scanned' => $scanned];
    }

    /**
     * Content rows worth reading at all.
     *
     * The `LIKE` prefilters in SQL so a library on a site with a million content rows only pulls
     * back the ones that mention an asset. Drafts and revisions are excluded: they are not places
     * a reader can see the file, and on a busy site they outnumber the real content several times
     * over, which would make every asset look used.
     */
    private function candidateQuery(): Query
    {
        return (new Query())
            ->select(['es.id', 'es.elementId', 'es.siteId', 'es.content', 'e.type'])
            ->from(['es' => CraftTable::ELEMENTS_SITES])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->where(['e.dateDeleted' => null, 'e.draftId' => null, 'e.revisionId' => null])
            ->andWhere(['not', ['es.content' => null]])
            ->andWhere([
                'or',
                ['like', 'es.content', '{asset:'],
                ['like', 'es.content', 'data-asset'],
            ]);
    }

    /**
     * Asset IDs referenced by a blob of serialized content.
     *
     * @return int[]
     */
    public function extractAssetRefs(string $content): array
    {
        $ids = [];
        $uids = [];

        if (preg_match_all(self::REF_PATTERN, $content, $matches)) {
            foreach ($matches[1] as $value) {
                if (ctype_digit($value)) {
                    $ids[(int)$value] = true;
                } else {
                    $uids[strtolower($value)] = true;
                }
            }
        }

        if (preg_match_all(self::ATTR_PATTERN, $content, $matches)) {
            foreach ($matches[1] as $value) {
                $ids[(int)$value] = true;
            }
        }

        if ($uids) {
            foreach ((new Query())
                ->select(['id'])
                ->from(CraftTable::ELEMENTS)
                ->where(['uid' => array_keys($uids), 'type' => Asset::class])
                ->column() as $id) {
                $ids[(int)$id] = true;
            }
        }

        return array_keys(array_filter($ids, fn($_, int $id) => $id > 0, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @param array<int,array<int,array{elementType:string,siteId:int}>> $found
     * @return array{rows:int,elements:int,assets:int}
     */
    private function record(array $found): array
    {
        if (!$found) {
            return ['rows' => 0, 'elements' => 0, 'assets' => 0];
        }

        $assetIds = [];

        foreach ($found as $refs) {
            foreach (array_keys($refs) as $assetId) {
                $assetIds[$assetId] = true;
            }
        }

        // Only IDs that are really assets. A stale `{asset:99:url}` left in a body after the file
        // was deleted is not usage, and inserting it would violate the foreign key.
        $realAssetIds = array_flip(array_map('intval', (new Query())
            ->select(['id'])
            ->from(CraftTable::ASSETS)
            ->where(['id' => array_keys($assetIds)])
            ->column()));

        $rows = [];

        foreach ($found as $elementId => $refs) {
            foreach ($refs as $assetId => $meta) {
                if (!isset($realAssetIds[$assetId])) {
                    continue;
                }

                $rows[] = [
                    $assetId,
                    $elementId,
                    $meta['elementType'],
                    $meta['siteId'],
                    null,
                    Usage::SOURCE_REFTAG,
                ];
            }
        }

        if (!$rows) {
            return ['rows' => 0, 'elements' => 0, 'assets' => 0];
        }

        $columns = ['assetId', 'elementId', 'elementType', 'siteId', 'fieldHandle', 'source'];
        $inserted = 0;

        // Chunked: a site with a lot of hand-written reference tags can produce tens of thousands of
        // rows, and one INSERT that big trips max_allowed_packet.
        foreach (array_chunk($rows, 1000) as $chunk) {
            $inserted += Db::batchInsert(UsageRecord::TABLE, $columns, $chunk);
        }

        return [
            'rows' => $inserted,
            'elements' => count($found),
            'assets' => count(array_intersect_key($assetIds, $realAssetIds)),
        ];
    }

    /**
     * Rescans one element's content across every site it exists in.
     *
     * Deliberately *not* scoped to a single site. Usage is recorded per element — the unique index
     * is `(assetId, elementId, source)`, with no site in it — so the delete below clears the
     * element's rows outright, and anything less than a full re-read would drop references that
     * only appear in another site's translation.
     *
     * That is exactly what an earlier version did: it deleted every row for the element and then
     * re-recorded from the one site it was handed, so on a multi-site save the last site processed
     * won and a reference living only in another translation vanished. The asset then read as
     * unused — on the screen people delete files from.
     */
    public function rescanElement(int $elementId): void
    {
        $rows = (new Query())
            ->select(['es.siteId', 'es.content', 'e.type'])
            ->from(['es' => CraftTable::ELEMENTS_SITES])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->where(['es.elementId' => $elementId])
            ->all();

        $refs = [];
        $elementType = null;
        $siteId = null;

        foreach ($rows as $row) {
            $elementType ??= (string)$row['type'];
            $siteId ??= (int)$row['siteId'];

            if (!is_string($row['content']) || $row['content'] === '') {
                continue;
            }

            foreach ($this->extractAssetRefs($row['content']) as $assetId) {
                // The site recorded is the first one the reference was seen in, and is
                // informational only — the row itself stands for the element as a whole.
                $refs[$assetId] ??= [
                    'elementType' => (string)$row['type'],
                    'siteId' => (int)$row['siteId'],
                ];
            }
        }

        // Delete and re-record together: a failure between the two would otherwise leave every
        // asset this element references reading as unused.
        Craft::$app->getDb()->transaction(function() use ($elementId, $refs) {
            Db::delete(UsageRecord::TABLE, [
                'elementId' => $elementId,
                'source' => Usage::SOURCE_REFTAG,
            ]);

            if ($refs) {
                $this->record([$elementId => $refs]);
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace justinholtweb\library\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\helpers\Db;
use justinholtweb\library\Plugin;
use justinholtweb\library\records\UsageRecord;
use yii\base\Component;

/**
 * Usage — the answer to "is anything actually pointing at this file?", which is the question you
 * have to answer before you dare delete anything.
 *
 * Two sources, deliberately kept apart:
 *
 * - `relation` — read live from Craft's own `relations` table on every query. Asset fields, and
 *   the asset references that CKEditor and Redactor record, all land there, so this half is
 *   always current and costs one indexed join.
 * - `reftag` — `{asset:123:url}` written by hand into a text field, and `data-asset-id` markup no
 *   relation was recorded for. Nothing indexes those, so they are found by a scan and cached in
 *   `library_usage`. This half is only as fresh as the last scan, and the UI says so.
 *
 * "Unused" means neither half has anything.
 */
class Usage extends Component
{
    /** @var array<int,int> Per-request cache of usage counts, keyed by asset ID. @see primeFor() */
    private array $countCache = [];

    public const SOURCE_RELATION = 'relation';
    public const SOURCE_REFTAG = 'reftag';

    /**
     * A correlated `EXISTS` subquery for relations pointing at an asset.
     *
     * One definition, used by the count, the list, the "unused" query and the condition rule alike.
     * They started out as four separate pieces of SQL and immediately disagreed: only one of them
     * excluded drafts and revisions, so an asset used in a single draft was reported as used by the
     * count and unused by the list. On a site with revisions turned on, revision rows outnumber the
     * real content several times over, which is enough to make almost everything look used.
     *
     * @param string $column The column to correlate against. Internal callers only — it is spliced
     *                       into SQL, so it must never come from a request.
     */
    public function relationExistsQuery(string $column = 'elements.id'): Query
    {
        return (new Query())
            ->from(['lr' => CraftTable::RELATIONS])
            ->innerJoin(['lre' => CraftTable::ELEMENTS], '[[lre.id]] = [[lr.sourceId]]')
            ->where("[[lr.targetId]] = [[$column]]")
            ->andWhere(['lre.dateDeleted' => null, 'lre.draftId' => null, 'lre.revisionId' => null]);
    }

    /** The reference-tag half of the same question. @see relationExistsQuery() */
    public function refExistsQuery(string $column = 'elements.id'): Query
    {
        return (new Query())
            ->from(['lu' => UsageRecord::TABLE])
            ->innerJoin(['lue' => CraftTable::ELEMENTS], '[[lue.id]] = [[lu.elementId]]')
            ->where("[[lu.assetId]] = [[$column]]")
            ->andWhere(['lu.source' => self::SOURCE_REFTAG])
            ->andWhere(['lue.dateDeleted' => null]);
    }

    /**
     * Usage count per asset ID, keyed by asset ID. Assets with no usage are present with `0`.
     *
     * @param int[] $assetIds
     * @return array<int,int>
     */
    public function getCountsForAssets(array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));

        if (!$assetIds) {
            return [];
        }

        $counts = array_fill_keys($assetIds, 0);

        // Relations: count distinct source elements, not rows. One entry with the same asset in
        // three fields is one place that would break, not three. Drafts, revisions and trashed
        // elements are excluded here for the same reason they are excluded from the list — see
        // relationExistsQuery().
        $relationRows = (new Query())
            ->select(['r.targetId', 'total' => 'COUNT(DISTINCT [[r.sourceId]])'])
            ->from(['r' => CraftTable::RELATIONS])
            ->innerJoin(['re' => CraftTable::ELEMENTS], '[[re.id]] = [[r.sourceId]]')
            ->where(['r.targetId' => $assetIds])
            ->andWhere(['re.dateDeleted' => null, 're.draftId' => null, 're.revisionId' => null])
            ->groupBy(['r.targetId'])
            ->all();

        foreach ($relationRows as $row) {
            $counts[(int)$row['targetId']] = (int)$row['total'];
        }

        $refRows = (new Query())
            ->select(['u.assetId', 'total' => 'COUNT(DISTINCT [[u.elementId]])'])
            ->from(['u' => UsageRecord::TABLE])
            ->innerJoin(['ue' => CraftTable::ELEMENTS], '[[ue.id]] = [[u.elementId]]')
            ->where(['u.assetId' => $assetIds, 'u.source' => self::SOURCE_REFTAG])
            ->andWhere(['ue.dateDeleted' => null])
            ->groupBy(['u.assetId'])
            ->all();

        foreach ($refRows as $row) {
            $counts[(int)$row['assetId']] += (int)$row['total'];
        }

        return $counts;
    }

    public function getCountForAsset(int $assetId): int
    {
        return $this->countCache[$assetId] ?? $this->getCountsForAssets([$assetId])[$assetId] ?? 0;
    }

    /**
     * Loads usage counts for a set of assets into the per-request cache.
     *
     * Same reason as Membership::primeFor() — the "Used in" column is rendered a row at a time, and
     * each count is two grouped queries.
     *
     * @param int[] $assetIds
     */
    public function primeFor(array $assetIds): void
    {
        $missing = array_values(array_diff(
            array_map('intval', $assetIds),
            array_keys($this->countCache),
        ));

        if (!$missing) {
            return;
        }

        foreach ($this->getCountsForAssets($missing) as $assetId => $count) {
            $this->countCache[$assetId] = $count;
        }
    }

    /**
     * Every place an asset is used, as a list of rows for the sidebar panel.
     *
     * @return array<int,array{element:ElementInterface|null,elementId:int,elementType:string,source:string,fieldHandle:string|null,siteId:int|null}>
     */
    public function getUsageForAsset(int $assetId): array
    {
        $found = [];

        $relationRows = (new Query())
            ->select(['r.sourceId', 'r.sourceSiteId', 'e.type', 'f.handle'])
            ->from(['r' => CraftTable::RELATIONS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[r.sourceId]]')
            ->leftJoin(['f' => CraftTable::FIELDS], '[[f.id]] = [[r.fieldId]]')
            ->where(['r.targetId' => $assetId, 'e.dateDeleted' => null])
            // Drafts and revisions of an entry are not places a reader can see the asset, and they
            // outnumber the real thing by an order of magnitude on a busy site.
            ->andWhere(['e.draftId' => null, 'e.revisionId' => null])
            ->all();

        foreach ($relationRows as $row) {
            $key = self::SOURCE_RELATION . ':' . $row['sourceId'];

            $found[$key] ??= [
                'elementId' => (int)$row['sourceId'],
                'elementType' => (string)$row['type'],
                'source' => self::SOURCE_RELATION,
                'fieldHandle' => $row['handle'] !== null ? (string)$row['handle'] : null,
                'siteId' => $row['sourceSiteId'] !== null ? (int)$row['sourceSiteId'] : null,
                'element' => null,
            ];
        }

        $refRows = (new Query())
            ->select(['u.elementId', 'u.elementType', 'u.siteId', 'u.fieldHandle'])
            ->from(['u' => UsageRecord::TABLE])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[u.elementId]]')
            ->where(['u.assetId' => $assetId, 'u.source' => self::SOURCE_REFTAG, 'e.dateDeleted' => null])
            ->all();

        foreach ($refRows as $row) {
            $key = self::SOURCE_REFTAG . ':' . $row['elementId'];

            $found[$key] ??= [
                'elementId' => (int)$row['elementId'],
                'elementType' => (string)$row['elementType'],
                'source' => self::SOURCE_REFTAG,
                'fieldHandle' => $row['fieldHandle'] !== null ? (string)$row['fieldHandle'] : null,
                'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : null,
                'element' => null,
            ];
        }

        return array_values($this->hydrate($found));
    }

    /**
     * Loads the referring elements in as few queries as there are element types, and walks nested
     * elements up to whatever owns them — "used in a Matrix block" is not an answer anybody can
     * click on, so the owner entry is what gets shown.
     */
    private function hydrate(array $found): array
    {
        $byType = [];

        foreach ($found as $key => $row) {
            $byType[$row['elementType']][$key] = $row['elementId'];
        }

        foreach ($byType as $type => $keyed) {
            if (!class_exists($type) || !is_subclass_of($type, ElementInterface::class)) {
                continue;
            }

            /** @var class-string<ElementInterface> $type */
            $elements = $type::find()
                ->id(array_values($keyed))
                ->status(null)
                ->siteId('*')
                ->unique()
                ->indexBy('id')
                ->all();

            foreach ($keyed as $key => $elementId) {
                $element = $elements[$elementId] ?? null;

                if ($element === null) {
                    continue;
                }

                $found[$key]['element'] = $element;
                $found[$key]['owner'] = $this->rootOwner($element);
            }
        }

        return $found;
    }

    /** Walks an element's owner chain to the outermost element that has its own edit screen. */
    private function rootOwner(ElementInterface $element): ElementInterface
    {
        $seen = [];

        while (true) {
            $owner = $element instanceof \craft\base\NestedElementInterface ? $element->getOwner() : null;

            if ($owner === null || isset($seen[spl_object_id($owner)])) {
                return $element;
            }

            $seen[spl_object_id($element)] = true;
            $element = $owner;
        }
    }

    /**
     * @param int|null $limit
     * @param int|null $offset Paged in SQL. The audit screen used to pull every unused ID back and
     *                         slice in PHP, which on a library with thirty thousand unused files
     *                         meant dragging thirty thousand rows across to render fifty.
     * @return int[] Asset IDs nothing points at.
     */
    public function getUnusedAssetIds(?int $limit = null, ?int $offset = null): array
    {
        $query = $this->unusedQuery()->orderBy(['a.id' => SORT_ASC]);

        if ($limit !== null) {
            $query->limit($limit);
        }

        if ($offset !== null) {
            $query->offset($offset);
        }

        return array_map('intval', $query->column());
    }

    public function getUnusedCount(): int
    {
        return (int)$this->unusedQuery()->count();
    }

    /** The query behind both the "Unused" source and the usage condition rule. */
    public function unusedQuery(): Query
    {
        $excluded = Plugin::getInstance()->getSettings()->getExcludedVolumeIds();

        $query = (new Query())
            ->select(['a.id'])
            ->from(['a' => CraftTable::ASSETS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[a.id]]')
            ->where(['e.dateDeleted' => null])
            ->andWhere(['not exists', $this->relationExistsQuery('a.id')])
            ->andWhere(['not exists', $this->refExistsQuery('a.id')]);

        if ($excluded) {
            $query->andWhere(['not', ['a.volumeId' => $excluded]]);
        }

        return $query;
    }

    /**
     * Records the reference-tag usage found on one element, replacing whatever was recorded for it
     * before. Called as elements save, and by the scanner.
     *
     * @param int[] $assetIds
     */
    public function setRefUsageForElement(ElementInterface $element, array $assetIds, ?string $fieldHandle = null): void
    {
        $elementId = $element->id;

        if ($elementId === null) {
            return;
        }

        $this->countCache = [];

        // Never record a draft or a revision: they are not places the asset is published, and
        // leaving them in makes every asset on the site look used.
        if ($element->getIsDraft() || $element->getIsRevision()) {
            return;
        }

        // Keyed on the element that actually contains the tag — a Matrix block, if that is where
        // it was typed — not on the entry that owns it. The scanner records the same way, so the two
        // paths cannot disagree, and a block that saves on its own still replaces only its own rows.
        // Walking up to something clickable is the display layer's job; see hydrate().
        Db::delete(UsageRecord::TABLE, [
            'elementId' => $elementId,
            'source' => self::SOURCE_REFTAG,
        ]);

        $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds), fn(int $id) => $id > 0)));

        if (!$assetIds) {
            return;
        }

        // Only assets that really exist: an FK violation here would break saving the entry.
        $assetIds = array_map('intval', (new Query())
            ->select(['id'])
            ->from(CraftTable::ASSETS)
            ->where(['id' => $assetIds])
            ->column());

        if (!$assetIds) {
            return;
        }

        $rows = array_map(fn(int $assetId) => [
            $assetId,
            $elementId,
            get_class($element),
            $element->siteId ?? null,
            $fieldHandle,
            self::SOURCE_REFTAG,
        ], $assetIds);

        Db::batchInsert(UsageRecord::TABLE, [
            'assetId', 'elementId', 'elementType', 'siteId', 'fieldHandle', 'source',
        ], $rows);
    }

    /** Drops every cached reference-tag row, so a scan starts from a clean slate. */
    public function clearRefUsage(): int
    {
        $this->countCache = [];

        return Db::delete(UsageRecord::TABLE, ['source' => self::SOURCE_REFTAG]);
    }

    /** Site-wide totals for the audit screen. */
    public function getStats(): array
    {
        $total = (int)(new Query())
            ->from(['a' => CraftTable::ASSETS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[a.id]]')
            ->where(['e.dateDeleted' => null])
            ->count();

        $unused = $this->getUnusedCount();

        return [
            'total' => $total,
            'unused' => $unused,
            'used' => max(0, $total - $unused),
            'refRows' => (int)(new Query())->from(UsageRecord::TABLE)->where(['source' => self::SOURCE_REFTAG])->count(),
        ];
    }
}

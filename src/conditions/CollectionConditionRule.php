<?php

declare(strict_types=1);

namespace justinholtweb\library\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\library\models\Collection;
use justinholtweb\library\Plugin;
use justinholtweb\library\records\CollectionAssetRecord;

/**
 * “Collection” — the rule that makes collections a real filter rather than a label.
 *
 * Registering this on `AssetCondition` is what puts collections into the asset index toolbar, into
 * asset field selector modals, into the sources a user builds themselves, and into asset field
 * settings — all four for the price of one class, and all four enforced in SQL.
 *
 * Values are collection UIDs, not IDs, so a saved filter survives being deployed to a site where
 * the auto-increment landed differently.
 */
class CollectionConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    /**
     * Gives the rule “has a value” and “is empty” operators, which is where WordPress's
     * “Uncategorized” view comes from — no separate rule needed.
     */
    protected bool $includeEmptyOperators = true;

    public function getLabel(): string
    {
        return Craft::t('library', 'Collection');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['libraryCollection'];
    }

    protected function options(): array
    {
        $options = [];

        $walk = function(array $collections, int $depth) use (&$walk, &$options): void {
            foreach ($collections as $collection) {
                /** @var Collection $collection */
                $options[] = [
                    'value' => $collection->uid,
                    // Non-breaking spaces: the select is a plain <select>, so nesting can only be
                    // shown by indenting the label, and normal spaces get collapsed away.
                    'label' => str_repeat("\u{00A0}\u{00A0}", $depth) . $collection->name,
                ];

                $walk($collection->children, $depth + 1);
            }
        };

        $walk(Plugin::getInstance()->collections->getTree(), 0);

        return $options;
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $membership = fn(?array $collectionIds) => (new Query())
            ->from(['lca' => CollectionAssetRecord::TABLE])
            ->where('[[lca.assetId]] = [[elements.id]]')
            ->andFilterWhere(['lca.collectionId' => $collectionIds]);

        switch ($this->operator) {
            case self::OPERATOR_EMPTY:
                $query->andWhere(['not exists', $membership(null)]);
                return;

            case self::OPERATOR_NOT_EMPTY:
                $query->andWhere(['exists', $membership(null)]);
                return;
        }

        $collectionIds = $this->selectedIds();

        // No collections chosen is not a filter. Returning early rather than matching nothing keeps
        // a half-filled rule from emptying the index while somebody is still picking.
        if (!$collectionIds) {
            return;
        }

        $query->andWhere([
            $this->operator === self::OPERATOR_NOT_IN ? 'not exists' : 'exists',
            $membership($collectionIds),
        ]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $assetId = $element->id;

        if ($assetId === null) {
            return $this->operator === self::OPERATOR_EMPTY;
        }

        $ownIds = Plugin::getInstance()->membership->getCollectionIdsForAsset($assetId);

        return match ($this->operator) {
            self::OPERATOR_EMPTY => $ownIds === [],
            self::OPERATOR_NOT_EMPTY => $ownIds !== [],
            self::OPERATOR_NOT_IN => !array_intersect($ownIds, $this->selectedIds()),
            default => !$this->selectedIds() || (bool)array_intersect($ownIds, $this->selectedIds()),
        };
    }

    /**
     * The selected collections plus everything nested inside them.
     *
     * Filtering by a parent and getting none of its children back is not what a hierarchy means —
     * “everything under Brand” is the whole reason to nest a collection in the first place.
     *
     * @return int[]
     */
    private function selectedIds(): array
    {
        $collections = Plugin::getInstance()->collections;
        $ids = [];

        foreach ($this->getValues() as $uid) {
            $collection = $collections->getCollectionByUid((string)$uid);

            if ($collection === null || $collection->id === null) {
                continue;
            }

            foreach ($collections->getIdWithDescendants($collection->id) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }
}

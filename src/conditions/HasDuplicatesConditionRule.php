<?php

declare(strict_types=1);

namespace justinholtweb\library\conditions;

use Craft;
use craft\base\conditions\BaseLightswitchConditionRule;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\library\records\HashRecord;

/**
 * “Has duplicate copies” — the asset is byte-identical to at least one other asset.
 *
 * Every member of a duplicate group matches, the original included. A filter that showed only the
 * copies would be deciding for the editor which file is the keeper, and that is a judgement call
 * about which one content already points at.
 *
 * Only assets that have been hashed can match, so the audit screen reports how many are still
 * pending rather than letting a half-hashed library read as a clean one.
 */
class HasDuplicatesConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('library', 'Has duplicate copies');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['libraryHasDuplicates'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        // Expressed entirely in SQL — no PHP-built ID list — so it stays correct and bounded on a
        // library with a hundred thousand files in it.
        $twin = (new Query())
            ->from(['h1' => HashRecord::TABLE])
            ->innerJoin(['h2' => HashRecord::TABLE], '[[h2.hash]] = [[h1.hash]]')
            ->innerJoin(['e2' => CraftTable::ELEMENTS], '[[e2.id]] = [[h2.assetId]]')
            ->where('[[h1.assetId]] = [[elements.id]]')
            ->andWhere('[[h2.assetId]] <> [[h1.assetId]]')
            ->andWhere(['not', ['h1.hash' => null]])
            // A twin in the trash is not a duplicate anybody needs to act on.
            ->andWhere(['e2.dateDeleted' => null]);

        $query->andWhere([$this->value ? 'exists' : 'not exists', $twin]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        if ($element->id === null) {
            return $this->matchValue(false);
        }

        $hash = (new Query())
            ->select(['hash'])
            ->from(HashRecord::TABLE)
            ->where(['assetId' => $element->id])
            ->scalar();

        if (!$hash) {
            return $this->matchValue(false);
        }

        $twins = (int)(new Query())
            ->from(['h' => HashRecord::TABLE])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[h.assetId]]')
            ->where(['h.hash' => $hash, 'e.dateDeleted' => null])
            ->andWhere(['not', ['h.assetId' => $element->id]])
            ->count();

        return $this->matchValue($twins > 0);
    }
}

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
use justinholtweb\library\Plugin;
use justinholtweb\library\records\UsageRecord;
use justinholtweb\library\services\Usage;

/**
 * “Is used anywhere” — the filter you reach for before deleting anything.
 *
 * Off means nothing in the site points at the file: no relation, and no reference tag found by the
 * last content scan. See {@see Usage} for why those are two different things.
 */
class UsedConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('library', 'Is used anywhere');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['libraryUsed'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        // Both halves come from the Usage service so this rule cannot drift from the count shown in
        // the index column or from the "Unused" figure on the audit screen.
        $usage = Plugin::getInstance()->usage;
        $relations = $usage->relationExistsQuery();
        $refs = $usage->refExistsQuery();

        if ($this->value) {
            $query->andWhere([
                'or',
                ['exists', $relations],
                ['exists', $refs],
            ]);

            return;
        }

        // Unused has to be *both* absent. Written as two NOT EXISTS rather than NOT (a OR b) so
        // each one can still use its own index.
        $query->andWhere(['not exists', $relations]);
        $query->andWhere(['not exists', $refs]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        if ($element->id === null) {
            return $this->matchValue(false);
        }

        return $this->matchValue(Plugin::getInstance()->usage->getCountForAsset($element->id) > 0);
    }
}

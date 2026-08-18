<?php

declare(strict_types=1);

namespace justinholtweb\library\conditions;

use Craft;
use craft\base\conditions\BaseSelectConditionRule;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

/**
 * “Orientation” — landscape, portrait or square.
 *
 * Craft ships width and height rules, but orientation is a comparison between the two columns
 * rather than either one against a number, so no combination of those two expresses it. It is also
 * the way people actually look for an image: “find me something landscape for the hero”.
 */
class OrientationConditionRule extends BaseSelectConditionRule implements ElementConditionRuleInterface
{
    public const LANDSCAPE = 'landscape';
    public const PORTRAIT = 'portrait';
    public const SQUARE = 'square';

    public function getLabel(): string
    {
        return Craft::t('library', 'Orientation');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['libraryOrientation'];
    }

    protected function options(): array
    {
        return [
            ['value' => self::LANDSCAPE, 'label' => Craft::t('library', 'Landscape')],
            ['value' => self::PORTRAIT, 'label' => Craft::t('library', 'Portrait')],
            ['value' => self::SQUARE, 'label' => Craft::t('library', 'Square')],
        ];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        if ($this->value === '') {
            return;
        }

        // A subquery against `assets` rather than a reference to the `assets` alias: this rule has
        // no say in whether the element table happens to be joined by the time the condition lands.
        $matching = (new Query())
            ->select(['a.id'])
            ->from(['a' => CraftTable::ASSETS])
            ->where('[[a.id]] = [[elements.id]]')
            ->andWhere(['not', ['a.width' => null]])
            ->andWhere(['not', ['a.height' => null]])
            ->andWhere(match ($this->value) {
                self::LANDSCAPE => '[[a.width]] > [[a.height]]',
                self::PORTRAIT => '[[a.width]] < [[a.height]]',
                default => '[[a.width]] = [[a.height]]',
            });

        $query->andWhere(['exists', $matching]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        if (!$element->width || !$element->height) {
            return false;
        }

        return $this->matchValue(match (true) {
            $element->width > $element->height => self::LANDSCAPE,
            $element->width < $element->height => self::PORTRAIT,
            default => self::SQUARE,
        });
    }
}

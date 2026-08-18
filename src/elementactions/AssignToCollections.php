<?php

declare(strict_types=1);

namespace justinholtweb\library\elementactions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\library\Plugin;
use justinholtweb\library\web\assets\cp\CpAsset;

/**
 * “Add to collections” — the bulk action a media library is judged on.
 *
 * Filing 300 photos one at a time is the reason people give up on categorising their media, so this
 * writes them all in a single insert.
 */
class AssignToCollections extends ElementAction
{
    /** @var int[] */
    public array $collectionIds = [];

    public function getTriggerLabel(): string
    {
        return Craft::t('library', 'Add to collections');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['collectionIds'], 'required'];
        $rules[] = [['collectionIds'], 'each', 'rule' => ['integer']];
        return $rules;
    }

    /**
     * A clean HTML id. The default is the class name, backslashes and all, which is not a valid id
     * and has to be escaped at every jQuery selector that looks for it.
     */
    public function getTriggerId(): string
    {
        return 'library-assign-collections';
    }

    public function getTriggerHtml(): ?string
    {
        // The picker styles and Craft.LibraryActionTrigger both live in this bundle, and nothing
        // else pulls it onto the asset index.
        Craft::$app->getView()->registerAssetBundle(CpAsset::class);

        return Craft::$app->getView()->renderTemplate('library/_actions/collections', [
            'type' => static::class,
            'triggerId' => $this->getTriggerId(),
            'label' => $this->getTriggerLabel(),
            'action' => 'assign',
            'collections' => Plugin::getInstance()->collections->getTree(),
        ]);
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $assetIds = array_map('intval', $query->ids());

        if (!$assetIds) {
            $this->setMessage(Craft::t('library', 'No assets were selected.'));
            return true;
        }

        Plugin::getInstance()->membership->assign($assetIds, $this->collectionIds);

        $names = array_map(
            fn($collection) => $collection->name,
            Plugin::getInstance()->collections->getCollectionsByIds($this->collectionIds),
        );

        $this->setMessage(Craft::t('library', '{n, plural, =1{1 asset} other{# assets}} added to {collections}.', [
            'n' => count($assetIds),
            'collections' => implode(', ', $names),
        ]));

        return true;
    }
}

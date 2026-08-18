<?php

declare(strict_types=1);

namespace justinholtweb\library\elementactions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\library\Plugin;
use justinholtweb\library\web\assets\cp\CpAsset;

/**
 * “Remove from collections”. Not destructive — it unfiles assets, it does not delete them — so it
 * deliberately does not ask for confirmation.
 */
class RemoveFromCollections extends ElementAction
{
    /** @var int[] */
    public array $collectionIds = [];

    public function getTriggerLabel(): string
    {
        return Craft::t('library', 'Remove from collections');
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
        return 'library-remove-collections';
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
            'action' => 'remove',
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

        $removed = Plugin::getInstance()->membership->remove($assetIds, $this->collectionIds);

        $this->setMessage(Craft::t('library', '{n, plural, =1{1 asset} other{# assets}} removed.', [
            'n' => $removed,
        ]));

        return true;
    }
}

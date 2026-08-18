<?php

declare(strict_types=1);

namespace justinholtweb\library\elementactions;

use Craft;
use craft\base\ElementAction;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\library\models\Settings;
use justinholtweb\library\Plugin;
use justinholtweb\library\web\assets\cp\CpAsset;

/**
 * “Set alt text” — fills alternative text in from the filename or the title.
 *
 * Machine-written alt text is a floor, not a ceiling, and the plugin says so in the UI. An image
 * described as "Brand logo dark" is still better for a screen reader than one described as nothing,
 * and it gives an editor something to correct rather than a blank field to find.
 */
class SetAltText extends ElementAction
{
    public string $source = Settings::ALT_SOURCE_FILENAME;

    /** Off by default: alt text somebody wrote by hand outranks anything derived from a filename. */
    public bool $overwrite = false;

    public function getTriggerLabel(): string
    {
        return Craft::t('library', 'Set alt text');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['source'], 'required'];
        $rules[] = [['source'], 'in', 'range' => [Settings::ALT_SOURCE_FILENAME, Settings::ALT_SOURCE_TITLE]];
        $rules[] = [['overwrite'], 'boolean'];
        return $rules;
    }

    /**
     * A clean HTML id. The default is the class name, backslashes and all, which is not a valid id
     * and has to be escaped at every jQuery selector that looks for it.
     */
    public function getTriggerId(): string
    {
        return 'library-set-alt';
    }

    public function getTriggerHtml(): ?string
    {
        // The picker styles and Craft.LibraryActionTrigger both live in this bundle, and nothing
        // else pulls it onto the asset index.
        Craft::$app->getView()->registerAssetBundle(CpAsset::class);

        return Craft::$app->getView()->renderTemplate('library/_actions/alt', [
            'type' => static::class,
            'triggerId' => $this->getTriggerId(),
            'label' => $this->getTriggerLabel(),
        ]);
    }

    /**
     * Narrows the action to assets this user may actually save.
     *
     * Craft's element index hands an action whatever the reader can *see*, and `viewAssets` is a
     * real permission without `saveAssets` — a read-only volume, or someone else's uploads when
     * `savePeerAssets` is not granted. Without this, one global "bulk edit" permission would let an
     * editor rename, move or re-describe files in volumes they only have read access to.
     */
    private function savableOnly(ElementQueryInterface $query): ElementQueryInterface
    {
        /** @var AssetQuery $query */
        return $query->savable();
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        /** @var Asset[] $assets */
        $assets = $this->savableOnly($query)->kind(Asset::KIND_IMAGE)->all();

        if (!$assets) {
            $this->setMessage(Craft::t('library', 'No images were selected. Alt text only applies to images.'));
            return true;
        }

        $changed = Plugin::getInstance()->alt->fill($assets, $this->source, $this->overwrite);

        if ($changed === 0) {
            $this->setMessage(Craft::t('library', 'Nothing to change — those images already have alt text.'));
            return true;
        }

        $this->setMessage(Craft::t('library', 'Alt text set on {n, plural, =1{1 image} other{# images}}. Worth a read before you rely on it.', [
            'n' => $changed,
        ]));

        return true;
    }
}

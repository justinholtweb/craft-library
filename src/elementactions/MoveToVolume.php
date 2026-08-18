<?php

declare(strict_types=1);

namespace justinholtweb\library\elementactions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use craft\errors\FsException;
use craft\models\VolumeFolder;
use justinholtweb\library\Plugin;
use justinholtweb\library\web\assets\cp\CpAsset;

/**
 * “Move to volume” — moves assets to another volume, keeping the element.
 *
 * Craft can move assets around inside a volume from the index, but not between volumes. Doing that
 * by hand means downloading, re-uploading and then fixing every entry that pointed at the old file,
 * because a re-upload is a new element. This keeps the same element, so every relation, every
 * collection and every reference tag still resolves afterwards.
 */
class MoveToVolume extends ElementAction
{
    public ?int $volumeId = null;

    /** Optional subfolder inside the target volume. Created if it does not exist. */
    public ?string $subfolder = null;

    public function getTriggerLabel(): string
    {
        return Craft::t('library', 'Move to volume');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['volumeId'], 'required'];
        $rules[] = [['volumeId'], 'integer'];
        $rules[] = [['subfolder'], 'string', 'max' => 255];
        return $rules;
    }

    /**
     * A clean HTML id. The default is the class name, backslashes and all, which is not a valid id
     * and has to be escaped at every jQuery selector that looks for it.
     */
    public function getTriggerId(): string
    {
        return 'library-move-volume';
    }

    public function getTriggerHtml(): ?string
    {
        // The picker styles and Craft.LibraryActionTrigger both live in this bundle, and nothing
        // else pulls it onto the asset index.
        Craft::$app->getView()->registerAssetBundle(CpAsset::class);

        $volumes = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            // Only volumes the reader is actually allowed to put files in.
            if (Craft::$app->getUser()->checkPermission("saveAssets:$volume->uid")) {
                $volumes[] = ['value' => $volume->id, 'label' => $volume->name];
            }
        }

        return Craft::$app->getView()->renderTemplate('library/_actions/move', [
            'type' => static::class,
            'triggerId' => $this->getTriggerId(),
            'label' => $this->getTriggerLabel(),
            'volumes' => $volumes,
        ]);
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $assets = $query->all();

        if (!$assets) {
            $this->setMessage(Craft::t('library', 'No assets were selected.'));
            return true;
        }

        $volume = Craft::$app->getVolumes()->getVolumeById((int)$this->volumeId);

        if ($volume === null) {
            $this->setMessage(Craft::t('library', 'That volume no longer exists.'));
            return false;
        }

        if (!Craft::$app->getUser()->checkPermission("saveAssets:$volume->uid")) {
            $this->setMessage(Craft::t('library', 'You are not allowed to save assets in that volume.'));
            return false;
        }

        try {
            $folder = $this->resolveFolder($volume->id);
        } catch (FsException $e) {
            $this->setMessage(Craft::t('library', 'Could not create the target folder: {error}', ['error' => $e->getMessage()]));
            return false;
        }

        if ($folder === null) {
            $this->setMessage(Craft::t('library', 'Could not find the target folder.'));
            return false;
        }

        $result = Plugin::getInstance()->renamer->move($assets, $folder);

        if ($result['failed'] > 0) {
            $this->setMessage(Craft::t('library', 'Moved {moved}, failed {failed}. First problem: {error}', [
                'moved' => $result['moved'],
                'failed' => $result['failed'],
                'error' => $result['errors'][0] ?? '',
            ]));

            return $result['moved'] > 0;
        }

        $this->setMessage(Craft::t('library', '{n, plural, =1{1 asset} other{# assets}} moved to {volume}.', [
            'n' => $result['moved'],
            'volume' => $volume->name,
        ]));

        return true;
    }

    /** The target folder, creating the subfolder chain if it was asked for and is missing. */
    private function resolveFolder(int $volumeId): ?VolumeFolder
    {
        $assetsService = Craft::$app->getAssets();
        $folder = $assetsService->getRootFolderByVolumeId($volumeId);

        if ($folder === null) {
            return null;
        }

        $subfolder = trim((string)$this->subfolder, '/ ');

        if ($subfolder === '') {
            return $folder;
        }

        foreach (explode('/', $subfolder) as $segment) {
            $segment = \craft\helpers\Assets::prepareAssetName($segment, false);

            if ($segment === '') {
                continue;
            }

            $child = $assetsService->findFolder([
                'parentId' => $folder->id,
                'name' => $segment,
            ]);

            if ($child === null) {
                $child = new VolumeFolder([
                    'parentId' => $folder->id,
                    'volumeId' => $volumeId,
                    'name' => $segment,
                    'path' => $folder->path . $segment . '/',
                ]);

                $assetsService->createFolder($child);
            }

            $folder = $child;
        }

        return $folder;
    }
}

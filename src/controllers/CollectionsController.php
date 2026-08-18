<?php

declare(strict_types=1);

namespace justinholtweb\library\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\library\models\Collection;
use justinholtweb\library\Plugin;
use justinholtweb\library\web\assets\cp\CpAsset;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Managing the collections themselves.
 */
class CollectionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Library's own screens need Library's stylesheet. Registering it here rather than in each
        // template keeps a new screen from quietly rendering unstyled.
        Craft::$app->getView()->registerAssetBundle(CpAsset::class);

        $this->requirePermission(Plugin::PERMISSION_MANAGE_COLLECTIONS);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $collections = $plugin->collections;

        // Descendant-inclusive, to match the badges in the asset index sidebar and the caption on
        // this screen. getAssetCounts() is the raw per-collection tally, which would show a parent
        // as empty while its children hold hundreds.
        $counts = [];

        foreach ($collections->getAllCollections() as $collection) {
            $counts[(int)$collection->id] = $collections->getAssetCount((int)$collection->id);
        }

        return $this->renderTemplate('library/collections/index', [
            'plugin' => $plugin,
            'tree' => $collections->getTree(),
            'counts' => $counts,
        ]);
    }

    public function actionEdit(?int $collectionId = null, ?Collection $collection = null): Response
    {
        $plugin = Plugin::getInstance();

        // A model may already be in hand: a failed save re-renders this action with the submitted
        // values and their errors rather than throwing the editor's typing away.
        if ($collection === null) {
            if ($collectionId !== null) {
                $collection = $plugin->collections->getCollectionById($collectionId);

                if ($collection === null) {
                    throw new NotFoundHttpException('Collection not found.');
                }
            } else {
                $collection = new Collection();
            }
        }

        $parentOptions = [['value' => '', 'label' => Craft::t('library', 'None')]];

        foreach ($plugin->collections->getAllCollections() as $option) {
            // A collection cannot be its own parent, and offering it as one only invites the
            // validation error.
            if ($collection->id !== null && $option->id === $collection->id) {
                continue;
            }

            $parentOptions[] = ['value' => $option->id, 'label' => $option->name];
        }

        return $this->renderTemplate('library/collections/edit', [
            'plugin' => $plugin,
            'collection' => $collection,
            'parentOptions' => $parentOptions,
            'colorOptions' => Collection::colorOptions(),
            'isNew' => $collection->id === null,
            'assetCount' => $collection->id !== null ? $plugin->collections->getAssetCount($collection->id) : 0,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = $this->request;
        $plugin = Plugin::getInstance();

        $id = $request->getBodyParam('collectionId');
        $collection = $id ? $plugin->collections->getCollectionById((int)$id) : new Collection();

        if ($collection === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        $collection->name = (string)$request->getBodyParam('name', $collection->name);
        $collection->handle = (string)$request->getBodyParam('handle', $collection->handle);
        $collection->description = $request->getBodyParam('description') ?: null;
        $collection->color = $request->getBodyParam('color') ?: null;

        $parentId = $request->getBodyParam('parentId');
        $collection->parentId = $parentId !== null && $parentId !== '' ? (int)$parentId : null;

        if (!$plugin->collections->saveCollection($collection)) {
            $this->setFailFlash(Craft::t('library', 'Couldn’t save collection.'));

            // Hands the populated model to the edit action so the errors are shown in place.
            return $this->runAction('edit', [
                'collectionId' => $collection->id,
                'collection' => $collection,
            ]);
        }

        $this->setSuccessFlash(Craft::t('library', 'Collection saved.'));

        return $this->redirectToPostedUrl($collection);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)$this->request->getRequiredBodyParam('id');

        Plugin::getInstance()->collections->deleteCollectionById($id);

        return $this->asSuccess(Craft::t('library', 'Collection deleted.'));
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = \craft\helpers\Json::decode($this->request->getRequiredBodyParam('ids'));

        Plugin::getInstance()->collections->reorder(is_array($ids) ? $ids : []);

        return $this->asSuccess();
    }

    /** Merges one collection into another — the fix for two collections meaning the same thing. */
    public function actionMerge(): Response
    {
        $this->requirePostRequest();

        $fromId = (int)$this->request->getRequiredBodyParam('fromId');
        $intoId = (int)$this->request->getRequiredBodyParam('intoId');

        if ($fromId === $intoId) {
            return $this->asFailure(Craft::t('library', 'Choose two different collections.'));
        }

        $plugin = Plugin::getInstance();
        $from = $plugin->collections->getCollectionById($fromId);
        $into = $plugin->collections->getCollectionById($intoId);

        if ($from === null || $into === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        $moved = $plugin->membership->mergeCollections($fromId, $intoId);

        $this->setSuccessFlash(Craft::t('library', '{n, plural, =1{1 asset} other{# assets}} moved from {from} into {into}.', [
            'n' => $moved,
            'from' => $from->name,
            'into' => $into->name,
        ]));

        return $this->redirect('library/collections');
    }
}

<?php

declare(strict_types=1);

namespace justinholtweb\library\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use justinholtweb\library\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The write path behind the Library panel on the asset edit screen.
 */
class MembershipController extends Controller
{
    public function actionSaveForAsset(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_ASSIGN);

        $assetId = (int)$this->request->getRequiredBodyParam('assetId');
        $asset = Craft::$app->getAssets()->getAssetById($assetId);

        if ($asset === null) {
            throw new NotFoundHttpException('Asset not found.');
        }

        // Collection membership is metadata about an asset, so being allowed to change it has to
        // follow being allowed to change the asset. Otherwise the panel is a way around volume
        // permissions.
        if (!Craft::$app->getElements()->canSave($asset)) {
            throw new ForbiddenHttpException('You are not allowed to edit this asset.');
        }

        $ids = $this->request->getBodyParam('collectionIds') ?: [];
        $ids = is_array($ids) ? array_map('intval', $ids) : [];

        // Only real collections: a stale ID posted from a page left open would otherwise fail on the
        // foreign key with a 500 instead of just being ignored.
        $valid = array_map(
            fn($collection) => (int)$collection->id,
            Plugin::getInstance()->collections->getCollectionsByIds($ids),
        );

        Plugin::getInstance()->membership->set([$assetId], $valid);

        return $this->asJson([
            'success' => true,
            'collectionIds' => $valid,
        ]);
    }

    /** Powers the bulk bar in Library's own screens. */
    public function actionBulk(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_ASSIGN);

        $assetIds = $this->request->getRequiredBodyParam('assetIds');
        $assetIds = is_array($assetIds) ? array_map('intval', $assetIds) : [];
        $collectionIds = $this->request->getRequiredBodyParam('collectionIds');
        $collectionIds = is_array($collectionIds) ? array_map('intval', $collectionIds) : [];
        $mode = (string)$this->request->getBodyParam('mode', 'assign');

        if (!$assetIds || !$collectionIds) {
            return $this->asFailure(Craft::t('library', 'Choose some assets and at least one collection.'));
        }

        // Filtered down to assets this user may actually edit, rather than trusting the posted list.
        $editable = Asset::find()->id($assetIds)->savable()->status(null)->ids();

        if (!$editable) {
            throw new ForbiddenHttpException('You are not allowed to edit those assets.');
        }

        $membership = Plugin::getInstance()->membership;

        $affected = $mode === 'remove'
            ? $membership->remove(array_map('intval', $editable), $collectionIds)
            : $membership->assign(array_map('intval', $editable), $collectionIds);

        return $this->asJson([
            'success' => true,
            'affected' => $affected,
            'skipped' => count($assetIds) - count($editable),
        ]);
    }
}

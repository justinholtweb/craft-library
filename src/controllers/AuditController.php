<?php

declare(strict_types=1);

namespace justinholtweb\library\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use justinholtweb\library\models\Settings;
use justinholtweb\library\services\Alt;
use justinholtweb\library\Plugin;
use justinholtweb\library\web\assets\cp\CpAsset;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Audit screens — unused files, duplicates and missing alt text.
 *
 * Pro only, and the edition check is here rather than only in the nav: a URL somebody bookmarked on
 * a trial must not keep working after the trial ends.
 */
class AuditController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Library's own screens need Library's stylesheet. Registering it here rather than in each
        // template keeps a new screen from quietly rendering unstyled.
        Craft::$app->getView()->registerAssetBundle(CpAsset::class);

        $this->requirePermission(Plugin::PERMISSION_AUDIT);

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException('Library Pro is required for audits.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('library/audit/index', [
            'plugin' => $plugin,
            'usage' => $plugin->usage->getStats(),
            'alt' => $plugin->alt->getStats(),
            'duplicates' => $plugin->duplicates->getDuplicateCount(),
            'pendingHashes' => $plugin->duplicates->getPendingHashCount(),
        ]);
    }

    public function actionUnused(): Response
    {
        $plugin = Plugin::getInstance();
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $perPage = 50;

        $total = $plugin->usage->getUnusedCount();
        $pageIds = $plugin->usage->getUnusedAssetIds($perPage, ($page - 1) * $perPage);

        return $this->renderTemplate('library/audit/unused', [
            'plugin' => $plugin,
            'assets' => $pageIds ? Asset::find()->id($pageIds)->status(null)->editable()->all() : [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'lastScan' => $plugin->usage->getStats()['refRows'],
        ]);
    }

    public function actionDuplicates(): Response
    {
        $plugin = Plugin::getInstance();
        $groups = $plugin->duplicates->getDuplicateGroups(50);

        // Only files the reader can view. Scoped here rather than in the service, which the console
        // command also uses and where there is no user to scope by. A group left with fewer than
        // two visible copies is dropped, so nobody learns a file exists in a volume they cannot see.
        $elementsService = Craft::$app->getElements();

        foreach ($groups as $i => $group) {
            $group['assets'] = array_values(array_filter($group['assets'], fn(Asset $asset) => $elementsService->canView($asset)));

            if (count($group['assets']) < 2) {
                unset($groups[$i]);
                continue;
            }

            $groups[$i] = $group;
        }

        $groups = array_values($groups);

        // Usage counts for every asset on the page in one query rather than one per file. The page
        // shows a count beside each copy, so asking per asset is a hundred queries on a busy library.
        $assetIds = [];

        foreach ($groups as $group) {
            foreach ($group['assets'] as $asset) {
                $assetIds[] = (int)$asset->id;
            }
        }

        return $this->renderTemplate('library/audit/duplicates', [
            'plugin' => $plugin,
            'groups' => $groups,
            'usageCounts' => $plugin->usage->getCountsForAssets($assetIds),
            'pendingHashes' => $plugin->duplicates->getPendingHashCount(),
        ]);
    }

    public function actionAlt(): Response
    {
        $plugin = Plugin::getInstance();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $perPage = 50;

        $query = $plugin->alt->missingAltQuery($siteId)->editable();
        $total = (int)$query->count();
        $assets = (clone $query)->offset(($page - 1) * $perPage)->limit($perPage)->all();

        // Worked out here rather than in the template: humanizeFilename() is a static helper, and
        // reaching a static through a plugin instance from Twig is the kind of thing that works
        // until somebody turns the sandbox up.
        $previews = [];

        foreach ($assets as $asset) {
            $previews[(int)$asset->id] = Alt::humanizeFilename((string)$asset->filename);
        }

        return $this->renderTemplate('library/audit/alt', [
            'plugin' => $plugin,
            'assets' => $assets,
            'previews' => $previews,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'stats' => $plugin->alt->getStats($siteId),
        ]);
    }

    /** Hashes one batch. Called repeatedly by the audit page so a big library does not time out. */
    public function actionHashBatch(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $done = $plugin->duplicates->hashBatch();
        $pending = $plugin->duplicates->getPendingHashCount();

        return $this->asJson([
            'success' => true,
            'hashed' => $done,
            'pending' => $pending,
            // The caller stops when this is true rather than when `pending` hits zero: a file that
            // cannot be read is recorded with a null hash and drops out of the pending count, but a
            // batch that hashed nothing while work remains would otherwise loop forever.
            'finished' => $done === 0 || $pending === 0,
        ]);
    }

    /** Repoints everything pointing at a copy onto the original, then trashes the copy. */
    public function actionResolveDuplicate(): Response
    {
        $this->requirePostRequest();

        $copyId = (int)$this->request->getRequiredBodyParam('copyId');
        $originalId = (int)$this->request->getRequiredBodyParam('originalId');
        $delete = (bool)$this->request->getBodyParam('delete', true);

        $assetsService = Craft::$app->getAssets();
        $copy = $assetsService->getAssetById($copyId);
        $original = $assetsService->getAssetById($originalId);

        if ($copy === null || $original === null) {
            throw new NotFoundHttpException('Asset not found.');
        }

        $elementsService = Craft::$app->getElements();

        if (!$elementsService->canDelete($copy) || !$elementsService->canSave($original)) {
            throw new ForbiddenHttpException('You are not allowed to resolve this duplicate.');
        }

        // Never trust the pairing from the request: without this, any two asset IDs would do, and
        // a repoint swaps every use of one file for the other across the whole site.
        if (!Plugin::getInstance()->duplicates->areDuplicates($copy, $original)) {
            throw new BadRequestHttpException('These assets are not known duplicates of each other.');
        }

        $result = Plugin::getInstance()->duplicates->repoint($copy, $original);

        if ($delete) {
            // Soft delete, always. Repointing is reversible from the trash; a hard delete of the
            // wrong file is not reversible at all.
            $elementsService->deleteElement($copy);
        }

        $this->setSuccessFlash(Craft::t('library', '{n, plural, =1{1 reference} other{# references}} repointed at {name}.', [
            'n' => $result['relations'] + $result['refs'],
            'name' => $original->getFilename(),
        ]));

        return $this->redirect('library/audit/duplicates');
    }

    /** Fills in alt text for every image currently missing it. */
    public function actionFillAlt(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_BULK_EDIT);

        $source = (string)$this->request->getBodyParam('source', Settings::ALT_SOURCE_FILENAME);

        if (!in_array($source, [Settings::ALT_SOURCE_FILENAME, Settings::ALT_SOURCE_TITLE], true)) {
            return $this->asFailure(Craft::t('library', 'Choose where the alt text should come from.'));
        }

        $plugin = Plugin::getInstance();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        // Capped per request. This saves every asset it touches, and a library with 20,000
        // undescribed images is not something to attempt inside one web request — the console
        // command exists for that, and the flash message says so.
        $limit = 200;
        $assets = $plugin->alt->missingAltQuery($siteId)->savable()->limit($limit)->all();

        $changed = $plugin->alt->fill($assets, $source, false, $siteId);
        $remaining = $plugin->alt->getMissingAltCount($siteId);

        if ($remaining > 0) {
            $this->setSuccessFlash(Craft::t('library', 'Alt text set on {n} images. {remaining} still to go — run it again, or use `craft library/alt/fill` for the rest.', [
                'n' => $changed,
                'remaining' => $remaining,
            ]));
        } else {
            $this->setSuccessFlash(Craft::t('library', 'Alt text set on {n} images. Worth a read before you rely on it.', ['n' => $changed]));
        }

        return $this->redirect('library/audit/alt');
    }

    /** Kicks off a full reference-tag scan. */
    public function actionScan(): Response
    {
        $this->requirePostRequest();

        $result = Plugin::getInstance()->scanner->scan();

        $this->setSuccessFlash(Craft::t('library', 'Scanned {scanned} content rows and found {rows} references across {elements} places.', [
            'scanned' => $result['scanned'],
            'rows' => $result['rows'],
            'elements' => $result['elements'],
        ]));

        return $this->redirect('library/audit');
    }
}

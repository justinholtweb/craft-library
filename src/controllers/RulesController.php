<?php

declare(strict_types=1);

namespace justinholtweb\library\controllers;

use Craft;
use craft\helpers\Assets as AssetsHelper;
use craft\web\Controller;
use justinholtweb\library\jobs\BackfillRule;
use justinholtweb\library\models\Settings;
use justinholtweb\library\models\UploadRule;
use justinholtweb\library\Plugin;
use justinholtweb\library\web\assets\cp\CpAsset;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Upload rules — Pro.
 */
class RulesController extends Controller
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

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException('Library Pro is required for upload rules.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('library/rules/index', [
            'plugin' => $plugin,
            'rules' => $plugin->rules->getAllRules(),
            'collections' => $plugin->collections->getAllCollections(),
            'volumes' => Craft::$app->getVolumes()->getAllVolumes(),
        ]);
    }

    public function actionEdit(?int $ruleId = null, ?UploadRule $rule = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($rule === null) {
            if ($ruleId !== null) {
                $rule = $plugin->rules->getRuleById($ruleId);

                if ($rule === null) {
                    throw new NotFoundHttpException('Rule not found.');
                }
            } else {
                $rule = new UploadRule();
            }
        }

        $volumeOptions = [['value' => '', 'label' => Craft::t('library', 'Any volume')]];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $volumeOptions[] = ['value' => $volume->id, 'label' => $volume->name];
        }

        $kindOptions = [];

        // The registry lives on the Assets *helper*, not on the element.
        foreach (AssetsHelper::getFileKinds() as $kind => $info) {
            $kindOptions[] = ['value' => $kind, 'label' => $info['label']];
        }

        $collectionOptions = [];

        foreach ($plugin->collections->getAllCollections() as $collection) {
            $collectionOptions[] = ['value' => $collection->id, 'label' => $collection->name];
        }

        return $this->renderTemplate('library/rules/edit', [
            'plugin' => $plugin,
            'rule' => $rule,
            'isNew' => $rule->id === null,
            'volumeOptions' => $volumeOptions,
            'kindOptions' => $kindOptions,
            'collectionOptions' => $collectionOptions,
            'altOptions' => [
                // Empty is stored as null: "no opinion", so the default from the settings applies.
                ['value' => '', 'label' => Craft::t('library', 'Use the default from settings')],
                ['value' => Settings::ALT_SOURCE_NONE, 'label' => Craft::t('library', 'Leave alone')],
                ['value' => Settings::ALT_SOURCE_FILENAME, 'label' => Craft::t('library', 'From the filename')],
                ['value' => Settings::ALT_SOURCE_TITLE, 'label' => Craft::t('library', 'From the title')],
            ],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = $this->request;
        $plugin = Plugin::getInstance();

        $id = $request->getBodyParam('ruleId');
        $rule = $id ? $plugin->rules->getRuleById((int)$id) : new UploadRule();

        if ($rule === null) {
            throw new NotFoundHttpException('Rule not found.');
        }

        $rule->name = (string)$request->getBodyParam('name', $rule->name);
        $rule->enabled = (bool)$request->getBodyParam('enabled', true);

        $volumeId = $request->getBodyParam('volumeId');
        $rule->volumeId = $volumeId !== null && $volumeId !== '' ? (int)$volumeId : null;

        $rule->folderPattern = $request->getBodyParam('folderPattern') ?: null;
        $rule->filenamePattern = $request->getBodyParam('filenamePattern') ?: null;
        $rule->kinds = (array)($request->getBodyParam('kinds') ?: []);
        $rule->collectionIds = array_map('intval', (array)($request->getBodyParam('collectionIds') ?: []));

        $altSource = $request->getBodyParam('altSource');
        $rule->altSource = $altSource !== null && $altSource !== '' ? (string)$altSource : null;

        if (!$plugin->rules->saveRule($rule)) {
            $this->setFailFlash(Craft::t('library', 'Couldn’t save rule.'));

            return $this->runAction('edit', [
                'ruleId' => $rule->id,
                'rule' => $rule,
            ]);
        }

        $this->setSuccessFlash(Craft::t('library', 'Rule saved.'));

        return $this->redirectToPostedUrl($rule);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        Plugin::getInstance()->rules->deleteRuleById((int)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess(Craft::t('library', 'Rule deleted.'));
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = \craft\helpers\Json::decode($this->request->getRequiredBodyParam('ids'));

        Plugin::getInstance()->rules->reorder(is_array($ids) ? $ids : []);

        return $this->asSuccess();
    }

    /** Applies the rules to assets already in the library, for a rule added after the fact. */
    public function actionBackfill(): Response
    {
        $this->requirePostRequest();

        $ruleId = (int)$this->request->getRequiredBodyParam('id');
        $plugin = Plugin::getInstance();
        $rule = $plugin->rules->getRuleById($ruleId);

        if ($rule === null) {
            throw new NotFoundHttpException('Rule not found.');
        }

        Craft::$app->getQueue()->push(new BackfillRule([
            'ruleId' => (int)$rule->id,
            'userId' => Craft::$app->getUser()->getId(),
        ]));

        $this->setSuccessFlash(Craft::t('library', 'Applying “{name}” to existing assets in the background.', [
            'name' => $rule->name,
        ]));

        return $this->redirect('library/rules');
    }
}

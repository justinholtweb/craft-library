<?php

declare(strict_types=1);

namespace justinholtweb\library\services;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Db;
use craft\helpers\Json;
use justinholtweb\library\models\Settings;
use justinholtweb\library\models\UploadRule;
use justinholtweb\library\Plugin;
use justinholtweb\library\records\UploadRuleRecord;
use yii\base\Component;

/**
 * Rules — files newly uploaded assets into collections automatically.
 *
 * This is what stops a media library going back to being a pile: an editor who has to remember to
 * categorise an upload will not, so the rules do it at the moment the file arrives.
 */
class Rules extends Component
{
    /** @var UploadRule[]|null */
    private ?array $all = null;

    /** @return UploadRule[] In evaluation order. */
    public function getAllRules(): array
    {
        if ($this->all === null) {
            $this->all = [];

            $rows = (new Query())
                ->select(['id', 'name', 'enabled', 'sortOrder', 'volumeId', 'folderPattern', 'kinds', 'filenamePattern', 'collectionIds', 'altSource', 'uid'])
                ->from(UploadRuleRecord::TABLE)
                ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
                ->all();

            foreach ($rows as $row) {
                $this->all[] = $this->toModel($row);
            }
        }

        return $this->all;
    }

    public function getRuleById(int $id): ?UploadRule
    {
        foreach ($this->getAllRules() as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    public function saveRule(UploadRule $rule, bool $runValidation = true): bool
    {
        if ($runValidation && !$rule->validate()) {
            return false;
        }

        $record = $rule->id !== null
            ? UploadRuleRecord::findOne($rule->id)
            : new UploadRuleRecord();

        if ($record === null) {
            return false;
        }

        $record->name = $rule->name;
        $record->enabled = $rule->enabled;
        $record->sortOrder = $rule->sortOrder;
        $record->volumeId = $rule->volumeId;
        $record->folderPattern = $rule->folderPattern !== '' ? $rule->folderPattern : null;
        $record->filenamePattern = $rule->filenamePattern !== '' ? $rule->filenamePattern : null;
        $record->kinds = Json::encode(array_values($rule->kinds));
        $record->collectionIds = Json::encode(array_values(array_map('intval', $rule->collectionIds)));
        $record->altSource = $rule->altSource;
        $record->save(false);

        $rule->id = (int)$record->id;
        $rule->uid = $record->uid;

        $this->all = null;

        return true;
    }

    public function deleteRuleById(int $id): bool
    {
        $deleted = Db::delete(UploadRuleRecord::TABLE, ['id' => $id]);
        $this->all = null;

        return $deleted > 0;
    }

    public function reorder(array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            Db::update(UploadRuleRecord::TABLE, ['sortOrder' => $i], ['id' => (int)$id], updateTimestamp: true);
        }

        $this->all = null;
    }

    /**
     * Runs the rules against a freshly created asset.
     *
     * Every matching rule contributes its collections — rules add up rather than the first one
     * winning, so "everything in this volume is Brand" and "anything called *-icon.svg is Icons"
     * can both be true of the same file. Alt text is the exception: the first rule that specifies a
     * source wins, because a file has only one description.
     *
     * @return array{collections:int,alt:bool}
     */
    public function applyToAsset(Asset $asset): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $applied = ['collections' => 0, 'alt' => false];

        if ($asset->id === null) {
            return $applied;
        }

        $collectionIds = [];
        $altSource = null;

        if (Plugin::getInstance()->isPro() && $settings->applyUploadRules) {
            foreach ($this->getAllRules() as $rule) {
                if (!$rule->matches($asset)) {
                    continue;
                }

                foreach ($rule->collectionIds as $id) {
                    $collectionIds[(int)$id] = true;
                }

                if ($altSource === null && $rule->altSource !== null && $rule->altSource !== Settings::ALT_SOURCE_NONE) {
                    $altSource = $rule->altSource;
                }
            }
        }

        // The site-wide default is the fallback, not an override: a rule that deliberately says
        // "leave alt text alone" for a volume of decorative textures has to be able to win.
        $altSource ??= $settings->defaultAltSource;

        if ($collectionIds) {
            $applied['collections'] = Plugin::getInstance()->membership->assign(
                [$asset->id],
                array_keys($collectionIds),
            );
        }

        if ($altSource !== Settings::ALT_SOURCE_NONE && trim((string)$asset->alt) === '') {
            $alt = Plugin::getInstance()->alt->deriveAlt($asset, $altSource);

            if ($alt !== null) {
                // Written straight to the row. This runs inside the asset's own save, and calling
                // saveElement() on the element being saved is how you get infinite recursion.
                Db::update(\craft\db\Table::ASSETS, ['alt' => $alt], ['id' => $asset->id], updateTimestamp: false);
                $asset->alt = $alt;
                $applied['alt'] = true;
            }
        }

        return $applied;
    }

    private function toModel(array $row): UploadRule
    {
        return new UploadRule([
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'enabled' => (bool)$row['enabled'],
            'sortOrder' => (int)$row['sortOrder'],
            'volumeId' => $row['volumeId'] !== null ? (int)$row['volumeId'] : null,
            'folderPattern' => $row['folderPattern'],
            'kinds' => $this->decodeList($row['kinds']),
            'filenamePattern' => $row['filenamePattern'],
            'collectionIds' => array_map('intval', $this->decodeList($row['collectionIds'])),
            'altSource' => $row['altSource'],
            'uid' => (string)$row['uid'],
        ]);
    }

    private function decodeList(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = Json::decodeIfJson($value);

        return is_array($decoded) ? $decoded : [];
    }
}

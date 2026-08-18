<?php

declare(strict_types=1);

namespace justinholtweb\library\models;

use Craft;
use craft\base\Model;

/**
 * Library's plugin settings.
 *
 * Nothing here is `required` — a fresh install has to be able to save any one setting without
 * every other one being filled in first.
 */
class Settings extends Model
{
    public const ALT_SOURCE_NONE = 'none';
    public const ALT_SOURCE_FILENAME = 'filename';
    public const ALT_SOURCE_TITLE = 'title';

    /** Whether collection sources are added to the asset index sidebar. */
    public bool $showCollectionSources = true;

    /** Whether collection sources are added inside asset field selector modals. */
    public bool $showCollectionSourcesInModals = true;

    /** Whether the audit sources (unused, duplicates, missing alt text) are added to the sidebar. */
    public bool $showAuditSources = true;

    /** Whether each collection source carries a count badge. One extra COUNT per collection. */
    public bool $showCollectionCounts = true;

    /** Whether the Library panel is added to the asset edit screen’s sidebar. */
    public bool $showAssetSidebarPanel = true;

    /** Whether new uploads are run through the upload rules. Pro. */
    public bool $applyUploadRules = true;

    /**
     * Where alt text comes from for a newly uploaded image when no upload rule says otherwise.
     * @see ALT_SOURCE_NONE, ALT_SOURCE_FILENAME, ALT_SOURCE_TITLE
     */
    public string $defaultAltSource = self::ALT_SOURCE_NONE;

    /** Whether usage is recorded as elements are saved, rather than only by a scan. */
    public bool $trackUsageOnSave = true;

    /**
     * Whether the usage scanner reads rich text and other content for `{asset:N:…}` reference
     * tags in addition to reading the relations table.
     */
    public bool $scanContentForRefs = true;

    /** Volumes (by UID) the usage scanner and audit tools ignore. Temp/cache volumes belong here. */
    public array $excludedVolumes = [];

    /**
     * Largest file the duplicate finder will hash, in megabytes. Hashing reads the whole file
     * through the filesystem adapter, which for a remote volume means downloading it.
     */
    public int $maxHashFileSizeMb = 64;

    /** How many assets one duplicate-scan batch hashes before yielding. */
    public int $hashBatchSize = 100;

    public function defineRules(): array
    {
        return [
            [
                [
                    'showCollectionSources',
                    'showCollectionSourcesInModals',
                    'showAuditSources',
                    'showCollectionCounts',
                    'showAssetSidebarPanel',
                    'applyUploadRules',
                    'trackUsageOnSave',
                    'scanContentForRefs',
                ],
                'boolean',
            ],
            [['defaultAltSource'], 'in', 'range' => [
                self::ALT_SOURCE_NONE,
                self::ALT_SOURCE_FILENAME,
                self::ALT_SOURCE_TITLE,
            ]],
            [['maxHashFileSizeMb'], 'integer', 'min' => 1, 'max' => 10240],
            [['hashBatchSize'], 'integer', 'min' => 1, 'max' => 1000],
            [['excludedVolumes'], 'safe'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'showCollectionSources' => Craft::t('library', 'Show collections in the asset index'),
            'showCollectionSourcesInModals' => Craft::t('library', 'Show collections in asset selector modals'),
            'showAuditSources' => Craft::t('library', 'Show audit sources'),
            'showCollectionCounts' => Craft::t('library', 'Show asset counts'),
            'showAssetSidebarPanel' => Craft::t('library', 'Show the Library panel on assets'),
            'applyUploadRules' => Craft::t('library', 'Apply upload rules'),
            'defaultAltSource' => Craft::t('library', 'Default alt text source'),
            'trackUsageOnSave' => Craft::t('library', 'Track usage as content is saved'),
            'scanContentForRefs' => Craft::t('library', 'Scan content for reference tags'),
            'excludedVolumes' => Craft::t('library', 'Excluded volumes'),
            'maxHashFileSizeMb' => Craft::t('library', 'Maximum file size to hash (MB)'),
            'hashBatchSize' => Craft::t('library', 'Hash batch size'),
        ];
    }

    /** Volume IDs resolved from the excluded volume UIDs, for use in queries. */
    public function getExcludedVolumeIds(): array
    {
        $volumes = Craft::$app->getVolumes();
        $ids = [];

        foreach ($this->excludedVolumes as $uid) {
            $volume = $volumes->getVolumeByUid($uid);
            if ($volume) {
                $ids[] = $volume->id;
            }
        }

        return $ids;
    }
}

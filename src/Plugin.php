<?php

declare(strict_types=1);

namespace justinholtweb\library;

use Craft;
use craft\base\conditions\BaseCondition;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\elements\conditions\assets\AssetCondition;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\ElementIndexTableAttributeEvent;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementSourcesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\DefineAttributeHtmlEvent;
use craft\services\Elements;
use craft\services\ElementSources;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\library\conditions\CollectionConditionRule;
use justinholtweb\library\conditions\HasDuplicatesConditionRule;
use justinholtweb\library\conditions\OrientationConditionRule;
use justinholtweb\library\conditions\UsedConditionRule;
use justinholtweb\library\elementactions\AssignToCollections;
use justinholtweb\library\elementactions\BulkRename;
use justinholtweb\library\elementactions\MoveToVolume;
use justinholtweb\library\elementactions\RemoveFromCollections;
use justinholtweb\library\elementactions\SetAltText;
use justinholtweb\library\models\Settings;
use justinholtweb\library\services\Alt;
use justinholtweb\library\services\Collections;
use justinholtweb\library\services\Duplicates;
use justinholtweb\library\services\Membership;
use justinholtweb\library\services\Renamer;
use justinholtweb\library\services\Rules;
use justinholtweb\library\services\Scanner;
use justinholtweb\library\services\Usage;
use justinholtweb\library\twig\LibraryVariable;
use justinholtweb\library\web\assets\cp\CpAsset;
use yii\base\Event;

/**
 * Library — a better media library for Craft CMS.
 *
 * @property-read Collections $collections
 * @property-read Membership $membership
 * @property-read Usage $usage
 * @property-read Scanner $scanner
 * @property-read Duplicates $duplicates
 * @property-read Alt $alt
 * @property-read Renamer $renamer
 * @property-read Rules $rules
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'library:view';
    public const PERMISSION_ASSIGN = 'library:assignCollections';
    public const PERMISSION_MANAGE_COLLECTIONS = 'library:manageCollections';
    public const PERMISSION_BULK_EDIT = 'library:bulkEdit';
    public const PERMISSION_AUDIT = 'library:audit';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'library';

    /**
     * @var array<int,true> Asset IDs that are moving out of a temporary uploads folder in this
     * request, and so are arriving in the library for the first time.
     * @see registerUploadHandling()
     */
    private array $arrivingAssetIds = [];

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'collections' => Collections::class,
                'membership' => Membership::class,
                'usage' => Usage::class,
                'scanner' => Scanner::class,
                'duplicates' => Duplicates::class,
                'alt' => Alt::class,
                'renamer' => Renamer::class,
                'rules' => Rules::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Condition rules and element actions are what make Library work inside Craft's own asset
        // index and inside every asset field modal, so they are registered unconditionally.
        $this->registerConditionRules();
        $this->registerElementActions();
        $this->registerElementSources();
        $this->registerTableAttributes();
        $this->registerAssetSidebar();
        $this->registerUploadHandling();
        $this->registerUsageTracking();
        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerGarbageCollection();
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('library', 'Library');

        $user = Craft::$app->getUser();

        if ($user->checkPermission(self::PERMISSION_MANAGE_COLLECTIONS)) {
            $item['subnav']['collections'] = [
                'label' => Craft::t('library', 'Collections'),
                'url' => 'library/collections',
            ];
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_AUDIT)) {
            $item['subnav']['audit'] = [
                'label' => Craft::t('library', 'Audit'),
                'url' => 'library/audit',
            ];
        }

        // Gated on the same permission RulesController requires, or the link is a 403.
        if ($this->isPro() && $user->checkPermission(self::PERMISSION_MANAGE_COLLECTIONS)) {
            $item['subnav']['rules'] = [
                'label' => Craft::t('library', 'Upload rules'),
                'url' => 'library/rules',
            ];
        }

        // Plugin settings are project config, and with allowAdminChanges off the settings screen
        // is a 403 — so on production the link would only lead to an error.
        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('library', 'Settings'),
                'url' => 'settings/plugins/library',
            ];
        }

        // A nav item with nothing under it is a dead link.
        if (empty($item['subnav'])) {
            return null;
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('library/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * Adds Library's filters to the asset condition.
     *
     * This one hook is why collections work in the asset index toolbar, in asset field selector
     * modals, in the sources a user builds by hand, and in asset field settings — Craft asks the
     * same condition class in all four places.
     */
    private function registerConditionRules(): void
    {
        Event::on(
            AssetCondition::class,
            BaseCondition::EVENT_REGISTER_CONDITION_RULES,
            function(RegisterConditionRulesEvent $event) {
                $event->conditionRules[] = CollectionConditionRule::class;
                $event->conditionRules[] = OrientationConditionRule::class;

                if ($this->isPro()) {
                    $event->conditionRules[] = UsedConditionRule::class;
                    $event->conditionRules[] = HasDuplicatesConditionRule::class;
                }
            },
        );
    }

    private function registerElementActions(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_ACTIONS,
            function(RegisterElementActionsEvent $event) {
                $user = Craft::$app->getUser();

                if ($user->checkPermission(self::PERMISSION_ASSIGN)) {
                    $event->actions[] = AssignToCollections::class;
                    $event->actions[] = RemoveFromCollections::class;
                }

                if ($user->checkPermission(self::PERMISSION_BULK_EDIT)) {
                    $event->actions[] = SetAltText::class;

                    if ($this->isPro()) {
                        $event->actions[] = BulkRename::class;
                        $event->actions[] = MoveToVolume::class;
                    }
                }
            },
        );
    }

    /**
     * Adds sources to the asset index sidebar.
     *
     * Only criteria that are *complete* go on a source. A source whose label promises a subset and
     * whose criteria only approximates one is worse than no source at all, so collections carry a
     * full asset ID list and the audit views — where the set can be enormous — are reached through
     * the filter toolbar and the Audit screen instead. See docs/plan.md for the reasoning.
     */
    private function registerElementSources(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_SOURCES,
            function(RegisterElementSourcesEvent $event) {
                $settings = $this->getSettings();

                if ($event->context === ElementSources::CONTEXT_SETTINGS) {
                    return;
                }

                if ($event->context === ElementSources::CONTEXT_MODAL && !$settings->showCollectionSourcesInModals) {
                    return;
                }

                // Console requests have no user to ask. `resave` and friends build source lists, and
                // a permission check there either throws or silently answers "no".
                if (
                    !Craft::$app->getRequest()->getIsConsoleRequest() &&
                    !Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)
                ) {
                    return;
                }

                $sources = [];

                if ($settings->showCollectionSources) {
                    $sources = array_merge($sources, $this->collectionSources());
                }

                if ($settings->showAuditSources) {
                    $sources = array_merge($sources, $this->auditSources());
                }

                if (!$sources) {
                    return;
                }

                $event->sources[] = [
                    'type' => ElementSources::TYPE_HEADING,
                    'heading' => Craft::t('library', 'Library'),
                ];

                array_push($event->sources, ...$sources);
            },
        );
    }

    /**
     * One source per collection, nested to match the tree.
     *
     * The criteria is an explicit asset ID list. It is the only asset query param that can express
     * "these particular files", and a source's criteria is the only part of a source Craft actually
     * enforces — `defaultFilter` is seeded into the filter toolbar only when no filter has been
     * opened yet, so a source built on it silently stops filtering after the reader touches the
     * funnel button.
     */
    private function collectionSources(): array
    {
        $collections = $this->collections;
        $tree = $collections->getTree();

        if (!$tree) {
            return [];
        }

        $showCounts = $this->getSettings()->showCollectionCounts;
        // One query for every collection's membership, not one per collection. This runs on every
        // asset index render and every asset field modal render.
        $memberships = $this->membership->getAllMemberships();

        $build = function(array $branch) use (&$build, $collections, $showCounts, $memberships): array {
            $sources = [];

            foreach ($branch as $collection) {
                $ids = [];

                foreach ($collections->getIdWithDescendants((int)$collection->id) as $id) {
                    foreach ($memberships[$id] ?? [] as $assetId) {
                        $ids[$assetId] = true;
                    }
                }

                $ids = array_keys($ids);

                $source = [
                    'key' => 'library:collection:' . $collection->uid,
                    'label' => $collection->name,
                    'hasThumbs' => true,
                    // `id(false)` rather than `id([])`: an empty array is no filter at all in Craft's
                    // param syntax, so an empty collection would list the entire library.
                    // `editable` scopes to volumes the user can view. Craft hides its own volume sources
                    // per volume; a collection spans volumes, so it has to ask for the scoping itself.
                    'criteria' => ['id' => $ids ?: false, 'editable' => true],
                    'defaultSort' => ['dateCreated', 'desc'],
                    'data' => [
                        'library-collection' => $collection->uid,
                        // Assets cannot be uploaded *into* a collection: a collection is not a
                        // folder, and Craft needs a folder to put the bytes in.
                        'can-upload' => false,
                    ],
                ];

                if ($collection->color !== null) {
                    $source['status'] = $collection->color;
                }

                if ($showCounts) {
                    // The ID list is already de-duplicated across the subtree, so its size is
                    // exactly what the source will list.
                    $source['badgeCount'] = count($ids);
                    $source['badgeLabel'] = Craft::t('library', 'Assets in this collection');
                }

                $nested = $build($collection->children);

                if ($nested) {
                    $source['nested'] = $nested;
                }

                $sources[] = $source;
            }

            return $sources;
        };

        return $build($tree);
    }

    /**
     * Audit sources whose criteria are entirely native asset params, so they are complete and
     * cost nothing to build. "Unused" and "Duplicates" are deliberately absent — neither can be
     * expressed in a native param, and both can run to thousands of rows, so they live on the
     * filter toolbar (as condition rules) and on the Audit screen.
     */
    private function auditSources(): array
    {
        return [
            [
                'key' => 'library:missing-alt',
                'label' => Craft::t('library', 'Missing alt text'),
                'hasThumbs' => true,
                'criteria' => ['kind' => Asset::KIND_IMAGE, 'hasAlt' => false, 'editable' => true],
                'defaultSort' => ['dateCreated', 'desc'],
                'data' => ['can-upload' => false],
            ],
        ];
    }

    private function registerTableAttributes(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function(RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes['libraryCollections'] = [
                    'label' => Craft::t('library', 'Collections'),
                ];

                if ($this->isPro()) {
                    $event->tableAttributes['libraryUsage'] = [
                        'label' => Craft::t('library', 'Used in'),
                    ];
                }
            },
        );

        // Craft fires this once per column, with the page's query built but not yet run, so both
        // Library columns can be loaded in one query each instead of one per row.
        Event::on(
            Asset::class,
            Element::EVENT_PREP_QUERY_FOR_TABLE_ATTRIBUTE,
            function(ElementIndexTableAttributeEvent $event) {
                if (!in_array($event->attribute, ['libraryCollections', 'libraryUsage'], true)) {
                    return;
                }

                // A clone, so reading the IDs cannot disturb the query Craft is about to run.
                $assetIds = array_map('intval', (clone $event->query)->ids());

                if (!$assetIds) {
                    return;
                }

                if ($event->attribute === 'libraryCollections') {
                    $this->membership->primeFor($assetIds);
                } else {
                    $this->usage->primeFor($assetIds);
                }
            },
        );

        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if ($asset->id === null) {
                    return;
                }

                match ($event->attribute) {
                    'libraryCollections' => $event->html = $this->collectionChipsHtml($asset),
                    'libraryUsage' => $event->html = $this->usageCountHtml($asset),
                    default => null,
                };
            },
        );
    }

    private function collectionChipsHtml(Asset $asset): string
    {
        $collections = $this->membership->getCollectionsForAsset((int)$asset->id);

        if (!$collections) {
            return '<span class="light">—</span>';
        }

        return Craft::$app->getView()->renderTemplate('library/_components/chips', [
            'collections' => $collections,
        ]);
    }

    private function usageCountHtml(Asset $asset): string
    {
        $count = $this->usage->getCountForAsset((int)$asset->id);

        if ($count === 0) {
            return '<span class="status-label red"><span class="status red"></span><span class="status-label-text">' .
                Craft::t('library', 'Unused') . '</span></span>';
        }

        return '<span class="light">' . Craft::t('library', '{n, plural, =1{1 place} other{# places}}', ['n' => $count]) . '</span>';
    }

    /** Adds the Library panel to the asset edit screen. */
    private function registerAssetSidebar(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                if (!$this->getSettings()->showAssetSidebarPanel) {
                    return;
                }

                /** @var Asset $asset */
                $asset = $event->sender;

                if ($asset->id === null || $asset->getIsRevision()) {
                    return;
                }

                if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
                    return;
                }

                Craft::$app->getView()->registerAssetBundle(CpAsset::class);

                $event->html .= Craft::$app->getView()->renderTemplate('library/_components/asset-panel', [
                    'asset' => $asset,
                    'plugin' => $this,
                    'collections' => $this->collections->getTree(),
                    'selectedIds' => $this->membership->getCollectionIdsForAsset((int)$asset->id),
                    'canAssign' => Craft::$app->getUser()->checkPermission(self::PERMISSION_ASSIGN),
                    'usage' => $this->isPro() ? $this->usage->getUsageForAsset((int)$asset->id) : [],
                ]);
            },
        );
    }

    /**
     * Runs upload rules over assets as they arrive in the library.
     *
     * "Arrives" is not the same as "is created". A file uploaded straight into a volume from the
     * assets index is created there, and `isNew` catches it. A file uploaded through an asset field
     * — by far the more common path — is created in the *temporary uploads* folder, which has no
     * volume, and is only moved into a real volume when the entry is saved. That move is a save of
     * an existing element, so `isNew` is false and rules based on it never fire at all.
     *
     * So the arrival is detected on the way in: before a save, an asset that has a volume now but
     * had none in the database is one that is landing in the library for the first time.
     */
    private function registerUploadHandling(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_SAVE_ELEMENT,
            function(ElementEvent $event) {
                $element = $event->element;

                // Deliberately *not* checking `volumeId` here. A move fires two saves: the first
                // still reports the old (null) volume with the target in `newFolderId`, and by the
                // second the row has already been updated — so an in-memory check misses on one and
                // the stored value has changed by the other. What matters is only whether the row
                // said "no volume" when this save began.
                if (
                    $event->isNew ||
                    !$element instanceof Asset ||
                    $element->id === null ||
                    $element->getIsDraft() ||
                    $element->getIsRevision() ||
                    $element->propagating ||
                    !$this->isPro() ||
                    !$this->getSettings()->applyUploadRules ||
                    isset($this->arrivingAssetIds[(int)$element->id])
                ) {
                    return;
                }

                // One indexed lookup, and only for an asset that already has an ID, is not a draft
                // and is not propagating — so this is off everybody else's save path.
                $previousVolumeId = (new Query())
                    ->select(['volumeId'])
                    ->from(CraftTable::ASSETS)
                    ->where(['id' => $element->id])
                    ->scalar();

                if ($previousVolumeId === null || $previousVolumeId === false || $previousVolumeId === '') {
                    $this->arrivingAssetIds[(int)$element->id] = true;
                }
            },
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function(ElementEvent $event) {
                $element = $event->element;

                if (!$this->shouldApplyRules($element, $event->isNew)) {
                    return;
                }

                $arriving = isset($this->arrivingAssetIds[(int)$element->id]);

                if (!$event->isNew && !$arriving) {
                    return;
                }

                unset($this->arrivingAssetIds[(int)$element->id]);

                $this->rules->applyToAsset($element);
            },
        );
    }

    /**
     * Whether upload rules should run for a saved asset.
     *
     * A file still sitting in the temporary uploads folder is not in the library yet, and filing it
     * would leave rows behind for something the editor may never save. Temp folders are the ones
     * with no volume — see `Assets::getUserTemporaryUploadFolder()`, which finds them by
     * `volumeId: ':empty:'`.
     */
    private function shouldApplyRules(mixed $element, bool $isNew): bool
    {
        if (!$element instanceof Asset || $element->id === null || $element->volumeId === null) {
            return false;
        }

        if ($element->getIsDraft() || $element->getIsRevision() || $element->propagating) {
            return false;
        }

        return $this->isPro() && $this->getSettings()->applyUploadRules;
    }

    /** Keeps the reference-tag half of usage current as content is saved. */
    private function registerUsageTracking(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function(ElementEvent $event) {
                $settings = $this->getSettings();

                if (!$this->isPro() || !$settings->trackUsageOnSave || !$settings->scanContentForRefs) {
                    return;
                }

                $element = $event->element;

                if ($element->id === null || $element->getIsDraft() || $element->getIsRevision()) {
                    return;
                }

                // Assets do not reference other assets, and re-reading every asset's content on
                // upload would double the cost of a bulk import for nothing.
                if ($element instanceof Asset) {
                    return;
                }

                // Runs on propagated saves too. Each run reads every site's content and rewrites
                // the element's rows wholesale, so repeating it is idempotent — and skipping
                // propagation would mean relying on the other sites' rows already being written
                // when the canonical save fires, which is not something to depend on.
                $this->scanner->rescanElement((int)$element->id);
            },
        );
    }

    private function registerRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['library'] = 'library/collections/index';
                $event->rules['library/collections'] = 'library/collections/index';
                $event->rules['library/collections/new'] = 'library/collections/edit';
                $event->rules['library/collections/<collectionId:\d+>'] = 'library/collections/edit';
                $event->rules['library/audit'] = 'library/audit/index';
                $event->rules['library/audit/duplicates'] = 'library/audit/duplicates';
                $event->rules['library/audit/unused'] = 'library/audit/unused';
                $event->rules['library/audit/alt'] = 'library/audit/alt';
                $event->rules['library/rules'] = 'library/rules/index';
                $event->rules['library/rules/new'] = 'library/rules/edit';
                $event->rules['library/rules/<ruleId:\d+>'] = 'library/rules/edit';
            },
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('library', 'Library'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('library', 'View collections and asset usage'),
                            'nested' => [
                                self::PERMISSION_ASSIGN => [
                                    'label' => Craft::t('library', 'Add assets to collections'),
                                ],
                                self::PERMISSION_MANAGE_COLLECTIONS => [
                                    'label' => Craft::t('library', 'Create and delete collections'),
                                ],
                                self::PERMISSION_BULK_EDIT => [
                                    'label' => Craft::t('library', 'Bulk edit assets'),
                                ],
                                self::PERMISSION_AUDIT => [
                                    'label' => Craft::t('library', 'Run library audits'),
                                ],
                            ],
                        ],
                    ],
                ];
            },
        );
    }

    private function registerTwig(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('library', LibraryVariable::class);
            },
        );
    }

    private function registerGarbageCollection(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function() {
                // Hard deletes are handled by the foreign keys. This is for rows whose asset row
                // went away without the element row going with it, which happens after a volume is
                // removed from a site that was mid-migration.
                $this->membership->pruneOrphans();
            },
        );
    }
}

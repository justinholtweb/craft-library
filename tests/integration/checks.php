<?php
/**
 * Library integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-library/tests/integration/checks.php
 *
 * These exercise the things a unit fixture cannot: the condition rules as real SQL against the real
 * database, membership writes through the service, alt text going through Craft's own `hasAlt` param,
 * duplicate grouping over actual file bytes, and the edition boundary.
 *
 * Idempotent and self-cleaning: every run builds its own collections and its own asset files inside
 * a temporary folder, and deletes both at the end even when a check fails.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Asset;
use craft\elements\conditions\assets\AssetCondition;
use craft\elements\Entry;
use craft\helpers\Db;
use justinholtweb\library\conditions\CollectionConditionRule;
use justinholtweb\library\conditions\HasDuplicatesConditionRule;
use justinholtweb\library\conditions\OrientationConditionRule;
use justinholtweb\library\conditions\UsedConditionRule;
use justinholtweb\library\models\Collection;
use justinholtweb\library\models\Settings;
use justinholtweb\library\models\UploadRule;
use justinholtweb\library\Plugin;
use justinholtweb\library\records\CollectionAssetRecord;
use justinholtweb\library\records\CollectionRecord;
use justinholtweb\library\services\Alt;
use justinholtweb\library\services\Usage;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();

if ($plugin === null) {
    echo "Library is not installed in this site.\n";
    exit(1);
}

// Pro for the duration of the run, restored in teardown so a harness left on Lite stays on it.
//
// The edition is set on the plugin instance rather than through switchEdition(), which writes to
// project config: what is under test is Library's own gating, and `Plugin::is()` reads this property
// directly. Going through project config also made the run order-dependent — once an earlier run had
// flushed an edition change to `config/project/*.yaml`, the next one died with
// StaleResourceException before it reached the checks that mattered.
$originalEdition = $plugin->edition;
$plugin->edition = Plugin::EDITION_PRO;

$created = ['collections' => [], 'assets' => [], 'folders' => [], 'entries' => []];

// ---------------------------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------------------------

$volume = null;

foreach (Craft::$app->getVolumes()->getAllVolumes() as $candidate) {
    if ($candidate->getFs()->hasUrls || true) {
        $volume = $candidate;
        break;
    }
}

if ($volume === null) {
    echo "This site has no asset volumes, so there is nothing to test against.\n";
    exit(1);
}

$assetsService = Craft::$app->getAssets();
$rootFolder = $assetsService->getRootFolderByVolumeId($volume->id);

// Reused if it survived a previous run. A suite that cannot start because it crashed last time is
// a suite nobody runs, and the crash is exactly when you most want to run it again.
$testFolder = $assetsService->findFolder([
    'parentId' => $rootFolder->id,
    'name' => 'library-checks',
]);

if ($testFolder === null) {
    $testFolder = new craft\models\VolumeFolder([
        'parentId' => $rootFolder->id,
        'volumeId' => $volume->id,
        'name' => 'library-checks',
        'path' => 'library-checks/',
    ]);

    $assetsService->createFolder($testFolder, true);
}

// Anything left in there by an interrupted run, so stale files cannot skew a count or a duplicate
// group in this one.
foreach (Asset::find()->folderId($testFolder->id)->status(null)->trashed(null)->all() as $stale) {
    Craft::$app->getElements()->deleteElement($stale, true);
}

$created['folders'][] = $testFolder;

/** Writes a real PNG of the given dimensions and returns the saved asset. */
function makeAsset(string $filename, int $width, int $height, ?string $forceBytes = null): Asset
{
    global $testFolder, $created;

    // The temp file has to keep the target extension: Craft infers the asset kind from it, and a
    // text file handed over as `.png` is read as an image and blows up in the image driver.
    $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'png';
    $tmp = Craft::$app->getPath()->getTempPath() . '/' . uniqid('libcheck', true) . '.' . $ext;

    if ($forceBytes !== null) {
        file_put_contents($tmp, $forceBytes);
    } else {
        $image = imagecreatetruecolor($width, $height);
        // A colour derived from the dimensions, so two assets of the same size are byte-identical
        // and two of different sizes are not. That is what the duplicate checks rely on.
        imagefill($image, 0, 0, imagecolorallocate($image, $width % 255, $height % 255, 128));
        imagepng($image, $tmp);
        imagedestroy($image);
    }

    $asset = new Asset();
    $asset->tempFilePath = $tmp;
    $asset->setFilename($filename);
    $asset->newFolderId = $testFolder->id;
    $asset->setVolumeId($testFolder->volumeId);
    $asset->avoidFilenameConflicts = true;
    $asset->setScenario(Asset::SCENARIO_CREATE);

    if (!Craft::$app->getElements()->saveElement($asset)) {
        throw new RuntimeException("Could not save $filename: " . implode(' ', $asset->getFirstErrors()));
    }

    $created['assets'][] = $asset;

    return $asset;
}

function makeCollection(string $name, ?int $parentId = null): Collection
{
    global $created;

    $collection = new Collection([
        'name' => $name,
        'handle' => 'libcheck' . substr(md5($name . microtime()), 0, 8),
        'parentId' => $parentId,
    ]);

    if (!Plugin::getInstance()->collections->saveCollection($collection)) {
        throw new RuntimeException("Could not save collection $name: " . implode(' ', $collection->getFirstErrors()));
    }

    $created['collections'][] = $collection;

    return $collection;
}

/** Runs an AssetCondition over the test folder only, and returns the matching asset IDs. */
function matchIds(AssetCondition $condition): array
{
    global $testFolder;

    $query = Asset::find()->folderId($testFolder->id)->status(null);
    $condition->modifyQuery($query);

    return array_map('intval', $query->ids());
}

try {
    echo "Library integration checks\n";
    echo "Volume: {$volume->name}\n";

    // -----------------------------------------------------------------------------------------
    section('Collections');

    $brand = makeCollection('Libcheck Brand');
    $logos = makeCollection('Libcheck Logos', $brand->id);
    $photos = makeCollection('Libcheck Photos');

    check('a collection saves and gets an id and uid', function() use ($brand) {
        return $brand->id > 0 && $brand->uid !== null;
    });

    check('handle is generated from the name when left blank', function() {
        $c = new Collection(['name' => 'Libcheck Auto Handle ' . uniqid()]);
        Plugin::getInstance()->collections->saveCollection($c);

        global $created;
        $created['collections'][] = $c;

        return str_starts_with($c->handle, 'libcheckAutoHandle') ?: "got {$c->handle}";
    });

    check('a duplicate handle is rejected', function() use ($brand) {
        $c = new Collection(['name' => 'Libcheck Clash', 'handle' => $brand->handle]);

        return Plugin::getInstance()->collections->saveCollection($c) === false
            && $c->hasErrors('handle');
    });

    check('the tree nests children under their parent', function() use ($brand, $logos) {
        foreach (Plugin::getInstance()->collections->getTree() as $node) {
            if ($node->id === $brand->id) {
                return count($node->children) === 1 && $node->children[0]->id === $logos->id;
            }
        }

        return 'Brand was not found at the top level of the tree';
    });

    check('a collection cannot be its own parent', function() use ($brand) {
        $brand->parentId = $brand->id;
        $ok = Plugin::getInstance()->collections->saveCollection($brand);
        $brand->parentId = null;

        return $ok === false;
    });

    check('a collection cannot be nested inside its own child', function() use ($brand, $logos) {
        $brand->parentId = $logos->id;
        $ok = Plugin::getInstance()->collections->saveCollection($brand);
        $brand->parentId = null;
        Plugin::getInstance()->collections->saveCollection($brand);

        return $ok === false;
    });

    check('getIdWithDescendants includes children', function() use ($brand, $logos) {
        $ids = Plugin::getInstance()->collections->getIdWithDescendants($brand->id);
        sort($ids);
        $expected = [$brand->id, $logos->id];
        sort($expected);

        return $ids === $expected ?: 'got ' . implode(',', $ids);
    });

    // -----------------------------------------------------------------------------------------
    section('Membership');

    $wide = makeAsset('libcheck-wide.png', 40, 20);
    $tall = makeAsset('libcheck-tall.png', 20, 40);
    $square = makeAsset('libcheck-square.png', 30, 30);

    $membership = $plugin->membership;

    check('assigning writes one row per pair', function() use ($membership, $wide, $tall, $logos) {
        $added = $membership->assign([$wide->id, $tall->id], [$logos->id]);

        return $added === 2 ?: "inserted $added";
    });

    check('assigning again is a no-op rather than a duplicate-key error', function() use ($membership, $wide, $tall, $logos) {
        $added = $membership->assign([$wide->id, $tall->id], [$logos->id]);

        return $added === 0 ?: "inserted $added the second time";
    });

    check('the unique index really is in place', function() use ($wide, $logos) {
        try {
            Db::insert(CollectionAssetRecord::TABLE, [
                'collectionId' => $logos->id,
                'assetId' => $wide->id,
            ]);
        } catch (Throwable) {
            return true;
        }

        return 'a duplicate membership row was accepted';
    });

    check('getCollectionsForAssets batches without losing anyone', function() use ($membership, $wide, $tall, $square, $logos) {
        $map = $membership->getCollectionsForAssets([$wide->id, $tall->id, $square->id]);

        return count($map[$wide->id]) === 1
            && $map[$wide->id][0]->id === $logos->id
            && $map[$square->id] === []
            ?: 'unexpected map: ' . json_encode(array_map('count', $map));
    });

    check('set() replaces membership outright', function() use ($membership, $wide, $photos, $logos) {
        $membership->set([$wide->id], [$photos->id]);
        $ids = $membership->getCollectionIdsForAsset($wide->id);

        return $ids === [$photos->id] ?: 'got ' . implode(',', $ids);
    });

    check('counts exclude trashed assets', function() use ($membership, $photos) {
        $throwaway = makeAsset('libcheck-throwaway.png', 11, 11);
        $membership->assign([$throwaway->id], [$photos->id]);

        $before = Plugin::getInstance()->collections->getAssetCount($photos->id);

        Craft::$app->getElements()->deleteElement($throwaway);
        Plugin::getInstance()->collections->clearCaches();

        $after = Plugin::getInstance()->collections->getAssetCount($photos->id);

        return $after === $before - 1 ?: "before $before, after $after";
    });

    check('deleting a collection promotes its children rather than deleting them', function() {
        $parent = makeCollection('Libcheck Doomed Parent');
        $child = makeCollection('Libcheck Surviving Child', $parent->id);

        Plugin::getInstance()->collections->deleteCollectionById($parent->id);

        $reloaded = Plugin::getInstance()->collections->getCollectionById($child->id);

        return $reloaded !== null && $reloaded->parentId === null
            ?: 'the child did not survive as a top-level collection';
    });

    check('deleting a collection removes its membership rows but not the assets', function() use ($membership) {
        $temp = makeCollection('Libcheck Temp');
        $asset = makeAsset('libcheck-keepme.png', 13, 13);
        $membership->assign([$asset->id], [$temp->id]);

        Plugin::getInstance()->collections->deleteCollectionById($temp->id);

        $rows = (new craft\db\Query())
            ->from(CollectionAssetRecord::TABLE)
            ->where(['collectionId' => $temp->id])
            ->count();

        return (int)$rows === 0 && Craft::$app->getAssets()->getAssetById($asset->id) !== null;
    });

    check('priming serves collections from cache instead of a query a row', function() use ($membership, $wide, $tall, $logos) {
        $membership->assign([$wide->id, $tall->id], [$logos->id]);
        $membership->clearCache();
        $membership->primeFor([(int)$wide->id, (int)$tall->id]);

        // Both must now answer from the primed cache, and answer correctly.
        $wideIds = $membership->getCollectionIdsForAsset((int)$wide->id);
        $tallIds = $membership->getCollectionIdsForAsset((int)$tall->id);

        return in_array($logos->id, $wideIds, true) && in_array($logos->id, $tallIds, true)
            ?: 'primed reads disagreed with the database';
    });

    check('a write invalidates the primed cache', function() use ($membership, $wide, $logos) {
        // The index renders after the action runs, so a stale cache here would show the old
        // collections immediately after an editor changed them.
        $membership->primeFor([(int)$wide->id]);
        $membership->remove([$wide->id], [$logos->id]);

        $after = $membership->getCollectionIdsForAsset((int)$wide->id);

        // Put it back for the checks that follow.
        $membership->assign([$wide->id], [$logos->id]);

        return !in_array($logos->id, $after, true)
            ?: 'the cache still reported a membership that had been removed';
    });

    // -----------------------------------------------------------------------------------------
    section('Collection condition rule');

    $membership->set([$wide->id], [$logos->id]);
    $membership->set([$tall->id], [$photos->id]);
    $membership->set([$square->id], []);
    $GLOBALS['libcheckWideId'] = (int)$wide->id;

    check('filtering by a collection returns only its assets', function() use ($logos, $wide) {
        $condition = new AssetCondition();
        $rule = new CollectionConditionRule(['condition' => $condition]);
        $rule->setValues([$logos->uid]);
        $condition->setConditionRules([$rule]);

        $ids = matchIds($condition);

        return in_array($wide->id, $ids, true) && count($ids) === 1
            ?: 'got ' . implode(',', $ids);
    });

    check('filtering by a parent includes assets in its children', function() use ($brand, $wide) {
        $condition = new AssetCondition();
        $rule = new CollectionConditionRule(['condition' => $condition]);
        $rule->setValues([$brand->uid]);
        $condition->setConditionRules([$rule]);

        // `wide` is in Logos, which is nested under Brand. Nothing is in Brand directly.
        return in_array($wide->id, matchIds($condition), true)
            ?: 'a parent filter did not reach into its child';
    });

    check('“is empty” finds the uncategorised assets', function() use ($square) {
        $condition = new AssetCondition();
        $rule = new CollectionConditionRule(['condition' => $condition]);
        $rule->operator = 'empty';
        $condition->setConditionRules([$rule]);

        $ids = matchIds($condition);

        return in_array((int)$square->id, $ids, true) && !in_array((int)$GLOBALS['libcheckWideId'], $ids, true)
            ?: 'uncategorised set was ' . implode(',', $ids);
    });

    check('“has a value” is the exact complement of “is empty”', function() use ($testFolder) {
        $all = Asset::find()->folderId($testFolder->id)->status(null)->ids();

        $empty = new AssetCondition();
        $emptyRule = new CollectionConditionRule(['condition' => $empty]);
        $emptyRule->operator = 'empty';
        $empty->setConditionRules([$emptyRule]);

        $notEmpty = new AssetCondition();
        $notEmptyRule = new CollectionConditionRule(['condition' => $notEmpty]);
        $notEmptyRule->operator = 'notempty';
        $notEmpty->setConditionRules([$notEmptyRule]);

        $union = array_merge(matchIds($empty), matchIds($notEmpty));
        sort($union);
        $all = array_map('intval', $all);
        sort($all);

        return $union === $all ?: 'the two halves do not add up to everything';
    });

    check('“not in” excludes the collection’s assets', function() use ($logos, $wide, $square) {
        $condition = new AssetCondition();
        $rule = new CollectionConditionRule(['condition' => $condition]);
        $rule->operator = 'ni';
        $rule->setValues([$logos->uid]);
        $condition->setConditionRules([$rule]);

        $ids = matchIds($condition);

        return !in_array($wide->id, $ids, true) && in_array($square->id, $ids, true)
            ?: 'got ' . implode(',', $ids);
    });

    check('a rule with nothing selected does not empty the index', function() use ($testFolder) {
        $condition = new AssetCondition();
        $rule = new CollectionConditionRule(['condition' => $condition]);
        $condition->setConditionRules([$rule]);

        $total = (int)Asset::find()->folderId($testFolder->id)->status(null)->count();

        return count(matchIds($condition)) === $total
            ?: 'a half-filled rule filtered the index';
    });

    check('matchElement agrees with the SQL', function() use ($logos, $wide, $square) {
        $condition = new AssetCondition();
        $rule = new CollectionConditionRule(['condition' => $condition]);
        $rule->setValues([$logos->uid]);

        return $rule->matchElement($wide) === true && $rule->matchElement($square) === false;
    });

    // -----------------------------------------------------------------------------------------
    section('Orientation rule');

    check('landscape, portrait and square are told apart', function() use ($wide, $tall, $square) {
        $results = [];

        foreach ([OrientationConditionRule::LANDSCAPE, OrientationConditionRule::PORTRAIT, OrientationConditionRule::SQUARE] as $value) {
            $condition = new AssetCondition();
            $rule = new OrientationConditionRule(['condition' => $condition]);
            $rule->value = $value;
            $condition->setConditionRules([$rule]);
            $results[$value] = matchIds($condition);
        }

        return in_array($wide->id, $results[OrientationConditionRule::LANDSCAPE], true)
            && in_array($tall->id, $results[OrientationConditionRule::PORTRAIT], true)
            && in_array($square->id, $results[OrientationConditionRule::SQUARE], true)
            && !in_array($wide->id, $results[OrientationConditionRule::PORTRAIT], true)
            ?: 'orientation buckets: ' . json_encode(array_map('count', $results));
    });

    check('orientation matchElement agrees with the SQL', function() use ($wide, $tall) {
        $condition = new AssetCondition();
        $rule = new OrientationConditionRule(['condition' => $condition]);
        $rule->value = OrientationConditionRule::LANDSCAPE;

        return $rule->matchElement($wide) === true && $rule->matchElement($tall) === false;
    });

    // -----------------------------------------------------------------------------------------
    section('Alt text');

    check('humanizeFilename strips noise and separators', function() {
        $cases = [
            'brand-logo_dark-2024_FINAL-v3.png' => 'Brand logo dark 2024',
            'IMG_4821.jpg' => '',
            'DSC00123-holiday.jpg' => 'Holiday',
            'teamPhoto.png' => 'Team photo',
            'photo-1234.jpg' => 'Photo',
            'NASA-image.jpg' => 'NASA image',
            'screenshot-2026-01-02.png' => '2026 01 02',
        ];

        foreach ($cases as $input => $expected) {
            $got = Alt::humanizeFilename($input);

            if ($got !== $expected) {
                return "'$input' gave '$got', expected '$expected'";
            }
        }

        return true;
    });

    check('missing alt text is found through Craft’s own hasAlt param', function() use ($wide) {
        $ids = Plugin::getInstance()->alt->getMissingAltAssetIds(Craft::$app->getSites()->getPrimarySite()->id);

        return in_array((int)$wide->id, array_map('intval', $ids), true)
            ?: 'a freshly uploaded image with no alt text was not reported';
    });

    check('filling alt text writes it and it stops being reported', function() use ($wide) {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $alt = Plugin::getInstance()->alt;
        $changed = $alt->fill([$wide], Settings::ALT_SOURCE_FILENAME, false, $siteId);

        $reloaded = Craft::$app->getAssets()->getAssetById($wide->id, $siteId);
        $stillMissing = in_array((int)$wide->id, array_map('intval', $alt->getMissingAltAssetIds($siteId)), true);

        return $changed === 1 && trim((string)$reloaded->alt) !== '' && !$stillMissing
            ?: "changed $changed, alt '" . ($reloaded->alt ?? '') . "', stillMissing " . var_export($stillMissing, true);
    });

    check('filling does not overwrite alt text somebody wrote', function() use ($tall) {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $tall->alt = 'Written by a person';
        Craft::$app->getElements()->saveElement($tall, false, false);

        Plugin::getInstance()->alt->fill([$tall], Settings::ALT_SOURCE_FILENAME, false, $siteId);

        $reloaded = Craft::$app->getAssets()->getAssetById($tall->id, $siteId);

        return $reloaded->alt === 'Written by a person' ?: "alt became '{$reloaded->alt}'";
    });

    check('overwrite: true does replace it', function() use ($tall) {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        Plugin::getInstance()->alt->fill([$tall], Settings::ALT_SOURCE_FILENAME, true, $siteId);

        $reloaded = Craft::$app->getAssets()->getAssetById($tall->id, $siteId);

        return $reloaded->alt !== 'Written by a person' && trim((string)$reloaded->alt) !== '';
    });

    check('non-images are never given alt text', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $txt = makeAsset('libcheck-notes.txt', 0, 0, "a text file, not an image\n");

        $changed = Plugin::getInstance()->alt->fill([$txt], Settings::ALT_SOURCE_FILENAME, true, $siteId);

        return $changed === 0 ?: 'alt text was written to a text file';
    });

    // -----------------------------------------------------------------------------------------
    section('Duplicates');

    $twinA = makeAsset('libcheck-twin-a.png', 25, 25);
    $twinB = makeAsset('libcheck-twin-b.png', 25, 25);

    check('two byte-identical files are hashed to the same value', function() use ($twinA, $twinB) {
        $plugin = Plugin::getInstance();
        $plugin->duplicates->hashAsset($twinA);
        $plugin->duplicates->hashAsset($twinB);

        $hashes = (new craft\db\Query())
            ->select(['hash'])
            ->from(justinholtweb\library\records\HashRecord::TABLE)
            ->where(['assetId' => [$twinA->id, $twinB->id]])
            ->column();

        return count($hashes) === 2 && $hashes[0] === $hashes[1] && $hashes[0] !== null
            ?: 'hashes: ' . json_encode($hashes);
    });

    check('the duplicate rule finds both members of the pair', function() use ($twinA, $twinB) {
        $condition = new AssetCondition();
        $rule = new HasDuplicatesConditionRule(['condition' => $condition]);
        $rule->value = true;
        $condition->setConditionRules([$rule]);

        $ids = matchIds($condition);

        return in_array($twinA->id, $ids, true) && in_array($twinB->id, $ids, true)
            ?: 'got ' . implode(',', $ids);
    });

    check('a unique file is not reported as a duplicate', function() use ($square) {
        $plugin = Plugin::getInstance();
        $plugin->duplicates->hashAsset($square);

        $condition = new AssetCondition();
        $rule = new HasDuplicatesConditionRule(['condition' => $condition]);
        $rule->value = true;
        $condition->setConditionRules([$rule]);

        return !in_array((int)$square->id, matchIds($condition), true);
    });

    check('a trashed twin stops counting as a duplicate', function() {
        $a = makeAsset('libcheck-ghost-a.png', 26, 26);
        $b = makeAsset('libcheck-ghost-b.png', 26, 26);

        $plugin = Plugin::getInstance();
        $plugin->duplicates->hashAsset($a);
        $plugin->duplicates->hashAsset($b);

        Craft::$app->getElements()->deleteElement($b);

        $condition = new AssetCondition();
        $rule = new HasDuplicatesConditionRule(['condition' => $condition]);
        $rule->value = true;
        $condition->setConditionRules([$rule]);

        return !in_array((int)$a->id, matchIds($condition), true)
            ?: 'the survivor is still flagged as a duplicate';
    });

    check('duplicate groups put the oldest file first', function() use ($twinA, $twinB) {
        foreach (Plugin::getInstance()->duplicates->getDuplicateGroups(200) as $group) {
            $ids = array_map(fn($a) => (int)$a->id, $group['assets']);

            if (in_array((int)$twinA->id, $ids, true)) {
                return $ids[0] === (int)$twinA->id ?: 'group order: ' . implode(',', $ids);
            }
        }

        return 'the twins were not grouped together';
    });

    check('getDuplicateAssetIds returns copies but never the original', function() use ($twinA, $twinB) {
        $ids = Plugin::getInstance()->duplicates->getDuplicateAssetIds();

        return in_array((int)$twinB->id, $ids, true) && !in_array((int)$twinA->id, $ids, true)
            ?: 'got ' . implode(',', array_slice($ids, 0, 10));
    });

    check('a file too large for the cap is left out of hashing', function() {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $original = $settings->maxHashFileSizeMb;

        // Nothing at all can be hashed at a cap of zero, which is the point.
        $settings->maxHashFileSizeMb = 0;
        $pending = $plugin->duplicates->getPendingHashCount();
        $settings->maxHashFileSizeMb = $original;

        return $pending === 0 ?: "$pending files were still considered at a 0 MB cap";
    });

    check('an unreadable file is not offered for hashing again', function() {
        // A null hash means "tried and could not be read". Counting it as "needs hashing" made every
        // batch re-select the same broken files, fail on all of them and return 0 — the signal both
        // hashing loops use to stop — so a handful of bad files halted hashing for the whole library.
        $a = makeAsset('libcheck-unreadable-a.png', 41, 41);
        $b = makeAsset('libcheck-unreadable-b.png', 41, 41);

        $plugin = Plugin::getInstance();
        $plugin->duplicates->hashAsset($a);

        // Stand in for a file the filesystem could not return.
        Db::update(justinholtweb\library\records\HashRecord::TABLE, ['hash' => null], ['assetId' => $a->id]);

        $pendingIds = (new craft\db\Query())
            ->select(['h.assetId'])
            ->from(['h' => justinholtweb\library\records\HashRecord::TABLE])
            ->where(['h.assetId' => $a->id])
            ->column();

        // The row exists with a null hash…
        if (count($pendingIds) !== 1) {
            return 'the null-hash row was not written';
        }

        // …and a hashing pass must now pick up only the sibling that has never been tried.
        $before = $plugin->duplicates->getPendingHashCount();
        $plugin->duplicates->hashBatch();
        $after = $plugin->duplicates->getPendingHashCount();

        $aStillPending = (new craft\db\Query())
            ->select(['assetId'])
            ->from(justinholtweb\library\records\HashRecord::TABLE)
            ->where(['assetId' => $a->id, 'hash' => null])
            ->exists();

        return $after < $before && $aStillPending
            ?: "pending went $before -> $after; the unreadable file " . ($aStillPending ? 'stayed null' : 'was rehashed');
    });

    check('merging a duplicate survives an element that references both files', function() {
        // library_usage is unique on (assetId, elementId, source). An element whose body
        // reference-tags both files has a row for each, and switching the copy's row over would
        // duplicate the original's — throwing 1062, rolling the transaction back and leaving that
        // pair permanently unresolvable.
        $original = makeAsset('libcheck-refboth-original.png', 43, 43);
        $copy = makeAsset('libcheck-refboth-copy.png', 43, 43);
        $referrer = makeAsset('libcheck-refboth-referrer.png', 44, 44);

        foreach ([$original->id, $copy->id] as $assetId) {
            Db::insert(justinholtweb\library\records\UsageRecord::TABLE, [
                'assetId' => $assetId,
                'elementId' => $referrer->id,
                'elementType' => Asset::class,
                'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                'source' => Usage::SOURCE_REFTAG,
            ]);
        }

        try {
            Plugin::getInstance()->duplicates->repoint($copy, $original);
        } catch (Throwable $e) {
            return 'repoint threw: ' . get_class($e) . ' ' . $e->getMessage();
        }

        $rows = (new craft\db\Query())
            ->select(['assetId'])
            ->from(justinholtweb\library\records\UsageRecord::TABLE)
            ->where(['elementId' => $referrer->id, 'source' => Usage::SOURCE_REFTAG])
            ->column();

        return array_map('intval', $rows) === [(int)$original->id]
            ?: 'usage rows left as ' . implode(',', $rows);
    });

    check('every write action narrows its query to savable assets', function() {
        // Craft's index hands an action whatever the reader can see, and viewAssets exists without
        // saveAssets. Asserted through the guard rather than the permission system, so the check
        // stays meaningful without building a restricted user and volume.
        foreach ([
            justinholtweb\library\elementactions\AssignToCollections::class,
            justinholtweb\library\elementactions\RemoveFromCollections::class,
            justinholtweb\library\elementactions\SetAltText::class,
            justinholtweb\library\elementactions\BulkRename::class,
            justinholtweb\library\elementactions\MoveToVolume::class,
        ] as $class) {
            $method = new ReflectionMethod($class, 'savableOnly');
            $method->setAccessible(true);

            $query = Asset::find();
            $method->invoke(new $class(), $query);

            if ($query->savable !== true) {
                $short = substr($class, strrpos($class, '\\') + 1);
                return "$short did not narrow its query to savable assets";
            }
        }

        return true;
    });

    check('a filename that looks like a query operator is still renamed', function() {
        // Craft reads a leading `not ` as an operator, so "not final.png" was parsed as
        // "filename != final.png" — matching nearly everything, so the rename was refused as a
        // collision with itself.
        $asset = makeAsset('not final.png', 45, 45);

        $rows = Plugin::getInstance()->renamer->preview([$asset], 'libcheck-operator-{id}.{ext}');

        return $rows[0]['problem'] === null
            ?: 'refused with: ' . $rows[0]['problem'];
    });

    // -----------------------------------------------------------------------------------------
    section('Usage');

    check('an unrelated asset is reported as unused', function() use ($square) {
        return Plugin::getInstance()->usage->getCountForAsset((int)$square->id) === 0
            ?: 'an asset nothing points at was counted as used';
    });

    check('the unused rule finds it', function() use ($square) {
        $condition = new AssetCondition();
        $rule = new UsedConditionRule(['condition' => $condition]);
        $rule->value = false;
        $condition->setConditionRules([$rule]);

        return in_array((int)$square->id, matchIds($condition), true);
    });

    $entry = null;
    $entryType = null;

    foreach (Craft::$app->getEntries()->getAllSections() as $section) {
        foreach ($section->getEntryTypes() as $candidateType) {
            foreach ($candidateType->getFieldLayout()->getCustomFields() as $field) {
                if ($field instanceof craft\fields\Assets) {
                    $entryType = [$section, $candidateType, $field];
                    break 3;
                }
            }
        }
    }

    if ($entryType !== null) {
        [$section, $type, $assetField] = $entryType;

        check('an asset picked in an asset field counts as used', function() use ($section, $type, $assetField, $square, &$entry) {
            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entry->typeId = $type->id;
            $entry->title = 'Libcheck usage ' . uniqid();
            $entry->setFieldValue($assetField->handle, [$square->id]);

            if (!Craft::$app->getElements()->saveElement($entry)) {
                return 'could not save the test entry: ' . implode(' ', $entry->getFirstErrors());
            }

            return Plugin::getInstance()->usage->getCountForAsset((int)$square->id) === 1
                ?: 'relation-based usage was not counted';
        });

        check('the used rule now finds it, and the unused rule does not', function() use ($square) {
            $used = new AssetCondition();
            $usedRule = new UsedConditionRule(['condition' => $used]);
            $usedRule->value = true;
            $used->setConditionRules([$usedRule]);

            $unused = new AssetCondition();
            $unusedRule = new UsedConditionRule(['condition' => $unused]);
            $unusedRule->value = false;
            $unused->setConditionRules([$unusedRule]);

            return in_array((int)$square->id, matchIds($used), true)
                && !in_array((int)$square->id, matchIds($unused), true);
        });

        check('resolving a duplicate repoints the entry off the copy onto the original', function() use ($section, $type, $assetField) {
            // Two byte-identical images, with an entry pointing at the copy. After repointing, that
            // entry has to reference the original — this is the one operation in the plugin that can
            // silently blank a field on somebody's site, so it is asserted end to end rather than by
            // counting rows.
            $original = makeAsset('libcheck-merge-original.png', 33, 33);
            $copy = makeAsset('libcheck-merge-copy.png', 33, 33);

            $referrer = new Entry();
            $referrer->sectionId = $section->id;
            $referrer->typeId = $type->id;
            $referrer->title = 'Libcheck merge ' . uniqid();
            $referrer->setFieldValue($assetField->handle, [$copy->id]);

            if (!Craft::$app->getElements()->saveElement($referrer)) {
                return 'could not save the referring entry: ' . implode(' ', $referrer->getFirstErrors());
            }

            global $created;
            $created['entries'][] = $referrer;

            // Read through a fresh relation query rather than the entry's field value. Craft
            // memoises an element per request and memoises that element's field value with it, so
            // re-fetching the entry hands back the same instance and the same already-resolved
            // list — which reads as empty once the copy is trashed, whatever the relation says.
            $related = fn() => array_map('intval', Asset::find()
                ->relatedTo(['sourceElement' => $referrer->id, 'field' => $assetField->handle])
                ->status(null)
                ->ids());

            $before = $related();

            if ($before !== [(int)$copy->id]) {
                return 'the entry never pointed at the copy: ' . (implode(',', $before) ?: 'nothing');
            }

            Plugin::getInstance()->duplicates->repoint($copy, $original);
            Craft::$app->getElements()->deleteElement($copy);

            $after = $related();

            return $after === [(int)$original->id]
                ?: 'after the merge the entry points at ' . (implode(',', $after) ?: 'nothing');
        });

        check('repointing does not leave the original related twice', function() use ($section, $type, $assetField) {
            // An entry that referenced *both* files is the case the unique index on `relations`
            // makes fatal: switching the copy's row over would collide with the one already there.
            $original = makeAsset('libcheck-both-original.png', 34, 34);
            $copy = makeAsset('libcheck-both-copy.png', 34, 34);

            $referrer = new Entry();
            $referrer->sectionId = $section->id;
            $referrer->typeId = $type->id;
            $referrer->title = 'Libcheck both ' . uniqid();
            $referrer->setFieldValue($assetField->handle, [$original->id, $copy->id]);

            if (!Craft::$app->getElements()->saveElement($referrer)) {
                return 'could not save the referring entry: ' . implode(' ', $referrer->getFirstErrors());
            }

            global $created;
            $created['entries'][] = $referrer;

            Plugin::getInstance()->duplicates->repoint($copy, $original);
            Craft::$app->getElements()->deleteElement($copy);

            $after = array_map('intval', Asset::find()
                ->relatedTo(['sourceElement' => $referrer->id, 'field' => $assetField->handle])
                ->status(null)
                ->ids());

            return $after === [(int)$original->id]
                ?: 'expected a single reference to the original, got ' . (implode(',', $after) ?: 'nothing');
        });

        check('the usage list names the entry that points at it', function() use ($square, &$entry) {
            foreach (Plugin::getInstance()->usage->getUsageForAsset((int)$square->id) as $place) {
                if ($place['elementId'] === (int)$entry->id) {
                    return $place['source'] === Usage::SOURCE_RELATION;
                }
            }

            return 'the entry was not listed as a place the asset is used';
        });
    } else {
        echo "  – skipped 3 relation checks: this site has no entry type with an Assets field\n";
    }

    check('reference tags are extracted from content', function() {
        $found = Plugin::getInstance()->scanner->extractAssetRefs(
            'text {asset:412:url} more <img data-asset-id="99"> and {asset:7@transform:url}'
        );

        sort($found);

        // 412, 99 and 7 are almost certainly not real assets here; extraction is what is under test.
        return $found === [7, 99, 412] ?: 'got ' . implode(',', $found);
    });

    check('a reference tag makes an asset count as used', function() use ($square) {
        $plugin = Plugin::getInstance();
        $before = $plugin->usage->getCountForAsset((int)$square->id);

        // Written straight in, standing in for a tag typed into a text field.
        Db::insert(justinholtweb\library\records\UsageRecord::TABLE, [
            'assetId' => $square->id,
            'elementId' => $square->id,
            'elementType' => Asset::class,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'source' => Usage::SOURCE_REFTAG,
        ]);

        $after = $plugin->usage->getCountForAsset((int)$square->id);

        Db::delete(justinholtweb\library\records\UsageRecord::TABLE, [
            'assetId' => $square->id,
            'source' => Usage::SOURCE_REFTAG,
        ]);

        return $after === $before + 1 ?: "before $before, after $after";
    });

    check('drafts are not counted as places an asset is used', function() use ($square) {
        // A draft has a draftId, and the usage query excludes those. Asserted on the query rather
        // than by making a draft, so the check stays meaningful on a site with no sections.
        $sql = Plugin::getInstance()->usage->unusedQuery()->createCommand()->getRawSql();

        return str_contains($sql, 'relations') ?: 'the unused query no longer reads the relations table';
    });

    // -----------------------------------------------------------------------------------------
    section('Bulk rename');

    check('a pattern previews without changing anything', function() use ($wide) {
        $before = $wide->getFilename();
        $rows = Plugin::getInstance()->renamer->preview([$wide], 'renamed-{index}.{ext}');

        $reloaded = Craft::$app->getAssets()->getAssetById($wide->id);

        return $rows[0]['to'] === 'renamed-1.png'
            && $rows[0]['changed'] === true
            && $reloaded->getFilename() === $before
            ?: 'preview: ' . json_encode($rows[0]['to']) . ', file is now ' . $reloaded->getFilename();
    });

    check('a preview flags two assets that would collide', function() use ($wide, $tall) {
        $rows = Plugin::getInstance()->renamer->preview([$wide, $tall], 'same-name.{ext}');

        return $rows[1]['problem'] !== null ?: 'a name collision within the selection was not flagged';
    });

    check('{index} resolves the collision', function() use ($wide, $tall) {
        $rows = Plugin::getInstance()->renamer->preview([$wide, $tall], 'ok-{index}.{ext}');

        return $rows[0]['problem'] === null && $rows[1]['problem'] === null
            && $rows[0]['to'] !== $rows[1]['to'];
    });

    check('a pattern with no extension keeps the original one', function() use ($square) {
        $name = Plugin::getInstance()->renamer->applyPattern('just-a-name', $square);

        return $name === 'just-a-name.png' ?: "got $name";
    });

    check('renaming actually moves the file', function() {
        $asset = makeAsset('libcheck-before.png', 17, 17);
        $result = Plugin::getInstance()->renamer->rename([$asset], 'libcheck-after-{id}.{ext}');

        $reloaded = Craft::$app->getAssets()->getAssetById($asset->id);

        return $result['renamed'] === 1
            && $reloaded->getFilename() === "libcheck-after-{$asset->id}.png"
            // The bytes have to have come with it, or this is a database-only rename.
            && $reloaded->getVolume()->getFs()->fileExists($reloaded->getPath())
            ?: 'result ' . json_encode($result) . ', now called ' . $reloaded->getFilename();
    });

    check('an empty pattern is refused rather than producing a nameless file', function() use ($square) {
        $rows = Plugin::getInstance()->renamer->preview([$square], '');

        return $rows[0]['problem'] !== null && $rows[0]['to'] === $square->getFilename();
    });

    // -----------------------------------------------------------------------------------------
    section('Upload rules');

    check('a glob pattern matches case-insensitively', function() {
        return UploadRule::globMatch('*-icon.SVG', 'brand-icon.svg') === true
            && UploadRule::globMatch('*-icon.svg', 'brand-logo.svg') === false
            && UploadRule::globMatch('brand/*', 'brand/sub/deep') === true
            && UploadRule::globMatch('logo?.png', 'logo1.png') === true
            && UploadRule::globMatch('logo?.png', 'logo12.png') === false;
    });

    check('a rule with no collections and no alt source is rejected', function() {
        $rule = new UploadRule(['name' => 'Libcheck empty rule']);

        return Plugin::getInstance()->rules->saveRule($rule) === false
            && $rule->hasErrors('collectionIds');
    });

    check('a rule matches on volume, kind and filename together', function() use ($volume, $photos) {
        $rule = new UploadRule([
            'name' => 'Libcheck rule ' . uniqid(),
            'volumeId' => $volume->id,
            'kinds' => [Asset::KIND_IMAGE],
            'filenamePattern' => 'libcheck-rule-*',
            'collectionIds' => [$photos->id],
        ]);

        if (!Plugin::getInstance()->rules->saveRule($rule)) {
            return 'the rule would not save: ' . implode(' ', $rule->getFirstErrors());
        }

        try {
            $matching = makeAsset('libcheck-rule-hit.png', 19, 19);
            $notMatching = makeAsset('libcheck-other-miss.png', 19, 21);

            $hit = in_array($photos->id, Plugin::getInstance()->membership->getCollectionIdsForAsset((int)$matching->id), true);
            $miss = in_array($photos->id, Plugin::getInstance()->membership->getCollectionIdsForAsset((int)$notMatching->id), true);

            return $hit && !$miss ?: 'hit ' . var_export($hit, true) . ', miss ' . var_export($miss, true);
        } finally {
            Plugin::getInstance()->rules->deleteRuleById((int)$rule->id);
        }
    });

    check('a rule fires for a file uploaded through an asset field, not just a direct upload', function() use ($volume, $photos, $testFolder) {
        // The common path: Craft creates the asset in the *temporary uploads* folder, which has no
        // volume, and only moves it into a real one when the entry is saved. That move is a save of
        // an existing element, so anything keyed on `isNew` never runs — which meant upload rules
        // silently did nothing for most of the uploads on a real site.
        $rule = new UploadRule([
            'name' => 'Libcheck field-upload rule ' . uniqid(),
            'filenamePattern' => 'libcheck-fieldupload-*',
            'collectionIds' => [$photos->id],
        ]);

        if (!Plugin::getInstance()->rules->saveRule($rule)) {
            return 'the rule would not save: ' . implode(' ', $rule->getFirstErrors());
        }

        try {
            $assetsService = Craft::$app->getAssets();
            $tempFolder = $assetsService->getUserTemporaryUploadFolder();

            $tmp = Craft::$app->getPath()->getTempPath() . '/' . uniqid('libcheckfield', true) . '.png';
            $image = imagecreatetruecolor(46, 46);
            imagefill($image, 0, 0, imagecolorallocate($image, 46, 46, 46));
            imagepng($image, $tmp);
            imagedestroy($image);

            $asset = new Asset();
            $asset->tempFilePath = $tmp;
            $asset->setFilename('libcheck-fieldupload-hit.png');
            $asset->newFolderId = $tempFolder->id;
            $asset->setVolumeId($tempFolder->volumeId);
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (!Craft::$app->getElements()->saveElement($asset)) {
                return 'could not create the temporary upload: ' . implode(' ', $asset->getFirstErrors());
            }

            global $created;
            $created['assets'][] = $asset;

            // Nothing should have been filed while it is still a temporary upload.
            if (Plugin::getInstance()->membership->getCollectionIdsForAsset((int)$asset->id) !== []) {
                return 'a file still in the temporary uploads folder was filed into a collection';
            }

            // Now the entry save moves it into a real volume.
            $assetsService->moveAsset($asset, $testFolder);

            $ids = Plugin::getInstance()->membership->getCollectionIdsForAsset((int)$asset->id);

            return in_array($photos->id, $ids, true)
                ?: 'after the move the asset was in ' . (implode(',', $ids) ?: 'no collection');
        } finally {
            Plugin::getInstance()->rules->deleteRuleById((int)$rule->id);
        }
    });

    check('an upload rule can seed alt text', function() use ($volume, $photos) {
        $rule = new UploadRule([
            'name' => 'Libcheck alt rule ' . uniqid(),
            'volumeId' => $volume->id,
            'filenamePattern' => 'libcheck-altrule-*',
            'collectionIds' => [$photos->id],
            'altSource' => Settings::ALT_SOURCE_FILENAME,
        ]);

        Plugin::getInstance()->rules->saveRule($rule);

        try {
            $asset = makeAsset('libcheck-altrule-brand-logo.png', 23, 23);
            $reloaded = Craft::$app->getAssets()->getAssetById((int)$asset->id);

            return trim((string)$reloaded->alt) !== '' ?: 'alt text was not seeded on upload';
        } finally {
            Plugin::getInstance()->rules->deleteRuleById((int)$rule->id);
        }
    });

    // -----------------------------------------------------------------------------------------
    section('Action wiring');

    check('every trigger param the JavaScript sends is a settable action property', function() {
        // ElementIndexesController hydrates an action by walking settingsAttributes() and reading a
        // body param of the same name. A mismatch is silent — the action just runs with its default,
        // so "preview only" could never be turned off and nobody would see an error.
        $expected = [
            justinholtweb\library\elementactions\AssignToCollections::class => ['collectionIds'],
            justinholtweb\library\elementactions\RemoveFromCollections::class => ['collectionIds'],
            justinholtweb\library\elementactions\SetAltText::class => ['source', 'overwrite'],
            justinholtweb\library\elementactions\BulkRename::class => ['pattern', 'preview'],
            justinholtweb\library\elementactions\MoveToVolume::class => ['volumeId', 'subfolder'],
        ];

        foreach ($expected as $class => $params) {
            $action = new $class();
            $attributes = $action->settingsAttributes();

            foreach ($params as $param) {
                if (!in_array($param, $attributes, true)) {
                    $short = substr($class, strrpos($class, '\\') + 1);
                    return "$short does not expose '$param'; it has: " . implode(', ', $attributes);
                }
            }
        }

        return true;
    });

    check('each action’s trigger id is a valid HTML id', function() {
        foreach ([
            justinholtweb\library\elementactions\AssignToCollections::class,
            justinholtweb\library\elementactions\RemoveFromCollections::class,
            justinholtweb\library\elementactions\SetAltText::class,
            justinholtweb\library\elementactions\BulkRename::class,
            justinholtweb\library\elementactions\MoveToVolume::class,
        ] as $class) {
            $id = (new $class())->getTriggerId();

            // The default is the class name, backslashes included, which no selector can address.
            if (!preg_match('/^[a-z][a-z0-9\-]*$/', $id)) {
                return "$class has trigger id '$id'";
            }
        }

        return true;
    });

    check('the collection and remove actions have distinct trigger ids', function() {
        // They share a template; if they shared an id too, one panel would drive both actions.
        $a = (new justinholtweb\library\elementactions\AssignToCollections())->getTriggerId();
        $b = (new justinholtweb\library\elementactions\RemoveFromCollections())->getTriggerId();

        return $a !== $b ?: "both are '$a'";
    });

    // -----------------------------------------------------------------------------------------
    section('Editions');

    check('Pro rules are registered on the asset condition', function() {
        $condition = new AssetCondition();
        $types = array_map(fn($rule) => get_class($rule), $condition->getSelectableConditionRules());

        return in_array(CollectionConditionRule::class, $types, true)
            && in_array(UsedConditionRule::class, $types, true)
            && in_array(HasDuplicatesConditionRule::class, $types, true)
            ?: 'registered: ' . implode(', ', array_map(fn($t) => substr($t, strrpos($t, '\\') + 1), $types));
    });

    check('Lite drops the Pro rules but keeps collections', function() {
        Plugin::getInstance()->edition = Plugin::EDITION_LITE;

        try {
            $condition = new AssetCondition();
            $types = array_map(fn($rule) => get_class($rule), $condition->getSelectableConditionRules());

            return in_array(CollectionConditionRule::class, $types, true)
                && in_array(OrientationConditionRule::class, $types, true)
                && !in_array(UsedConditionRule::class, $types, true)
                && !in_array(HasDuplicatesConditionRule::class, $types, true)
                ?: 'Lite registered: ' . implode(', ', array_map(fn($t) => substr($t, strrpos($t, '\\') + 1), $types));
        } finally {
            Plugin::getInstance()->edition = Plugin::EDITION_PRO;
        }
    });

    check('Lite does not apply upload rules', function() use ($volume, $photos) {
        $rule = new UploadRule([
            'name' => 'Libcheck lite rule ' . uniqid(),
            'volumeId' => $volume->id,
            'filenamePattern' => 'libcheck-literule-*',
            'collectionIds' => [$photos->id],
        ]);

        Plugin::getInstance()->rules->saveRule($rule);
        Plugin::getInstance()->edition = Plugin::EDITION_LITE;

        try {
            $asset = makeAsset('libcheck-literule-hit.png', 29, 31);
            $ids = Plugin::getInstance()->membership->getCollectionIdsForAsset((int)$asset->id);

            return !in_array($photos->id, $ids, true) ?: 'a Pro-only upload rule ran on Lite';
        } finally {
            Plugin::getInstance()->edition = Plugin::EDITION_PRO;
            Plugin::getInstance()->rules->deleteRuleById((int)$rule->id);
        }
    });

    check('usageCount through the Twig variable is null on Lite', function() use ($square) {
        Plugin::getInstance()->edition = Plugin::EDITION_LITE;

        try {
            return (new justinholtweb\library\twig\LibraryVariable())->usageCount($square) === null;
        } finally {
            Plugin::getInstance()->edition = Plugin::EDITION_PRO;
        }
    });

    // -----------------------------------------------------------------------------------------
    section('Twig variable');

    check('craft.library.assets() returns a collection’s assets, children included', function() use ($brand, $wide) {
        $variable = new justinholtweb\library\twig\LibraryVariable();
        $ids = array_map('intval', $variable->assets($brand->handle)->status(null)->ids());

        return in_array((int)$wide->id, $ids, true) ?: 'got ' . implode(',', $ids);
    });

    check('an unknown handle returns nothing rather than everything', function() {
        $variable = new justinholtweb\library\twig\LibraryVariable();

        return $variable->assets('no-such-collection-handle')->status(null)->count() === 0
            ?: 'an unknown handle returned the whole library';
    });

    check('an empty collection returns nothing rather than everything', function() {
        $empty = makeCollection('Libcheck Empty ' . uniqid());
        $variable = new justinholtweb\library\twig\LibraryVariable();

        return $variable->assets($empty->handle)->status(null)->count() === 0
            ?: 'an empty collection returned the whole library';
    });

    check('isIn() respects nesting', function() use ($brand, $logos, $photos, $wide) {
        $variable = new justinholtweb\library\twig\LibraryVariable();

        return $variable->isIn($wide, $logos->handle) === true
            && $variable->isIn($wide, $brand->handle) === true
            && $variable->isIn($wide, $photos->handle) === false;
    });

    // -----------------------------------------------------------------------------------------
    section('Sources');

    check('an empty collection’s source criteria filters to nothing, not everything', function() {
        $empty = makeCollection('Libcheck Source Empty ' . uniqid());

        $event = new craft\events\RegisterElementSourcesEvent([
            'context' => craft\services\ElementSources::CONTEXT_INDEX,
            'sources' => [],
        ]);

        yii\base\Event::trigger(Asset::class, craft\base\Element::EVENT_REGISTER_SOURCES, $event);

        $found = null;

        foreach ($event->sources as $source) {
            if (($source['key'] ?? '') === 'library:collection:' . $empty->uid) {
                $found = $source;
                break;
            }
        }

        if ($found === null) {
            return 'the new collection did not get a source';
        }

        return ($found['criteria']['id'] ?? null) === false
            ?: 'criteria was ' . json_encode($found['criteria']);
    });

    check('the missing-alt source uses only native, complete criteria', function() {
        // Read through Craft's own event rather than Asset::sources(), which memoises statically per
        // request — by this point in the run it has already been called and cached.
        $event = new craft\events\RegisterElementSourcesEvent([
            'context' => craft\services\ElementSources::CONTEXT_INDEX,
            'sources' => [],
        ]);

        yii\base\Event::trigger(Asset::class, craft\base\Element::EVENT_REGISTER_SOURCES, $event);

        foreach ($event->sources as $source) {
            if (($source['key'] ?? '') === 'library:missing-alt') {
                return ($source['criteria']['hasAlt'] ?? null) === false
                    && ($source['criteria']['kind'] ?? null) === Asset::KIND_IMAGE;
            }
        }

        return 'the missing-alt source was not registered';
    });

    check('a nested collection source carries the complete asset id list', function() use ($logos, $wide) {
        $event = new craft\events\RegisterElementSourcesEvent([
            'context' => craft\services\ElementSources::CONTEXT_INDEX,
            'sources' => [],
        ]);

        yii\base\Event::trigger(Asset::class, craft\base\Element::EVENT_REGISTER_SOURCES, $event);

        // Logos is nested under Brand, so its source is inside Brand's `nested` list rather than at
        // the top level — which is the mapping being asserted as much as the criteria is.
        $find = function(array $sources, string $key) use (&$find): ?array {
            foreach ($sources as $source) {
                if (($source['key'] ?? '') === $key) {
                    return $source;
                }

                $nested = $find($source['nested'] ?? [], $key);

                if ($nested !== null) {
                    return $nested;
                }
            }

            return null;
        };

        $source = $find($event->sources, 'library:collection:' . $logos->uid);

        if ($source === null) {
            return 'the collection source was not registered at any depth';
        }

        $ids = array_map('intval', (array)($source['criteria']['id'] ?? []));

        return in_array((int)$wide->id, $ids, true)
            ?: 'the source criteria did not include the collection’s asset';
    });

} finally {
    // -----------------------------------------------------------------------------------------
    section('Teardown');

    $elements = Craft::$app->getElements();

    if (isset($entry) && $entry !== null && $entry->id) {
        $elements->deleteElement($entry, true);
    }

    foreach ($created['entries'] as $madeEntry) {
        try {
            $elements->deleteElement($madeEntry, true);
        } catch (Throwable $e) {
            echo "  ! could not delete entry {$madeEntry->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($created['assets'] as $asset) {
        try {
            $fresh = Asset::find()->id($asset->id)->status(null)->trashed(null)->one();

            if ($fresh !== null) {
                $elements->deleteElement($fresh, true);
            }
        } catch (Throwable $e) {
            echo "  ! could not delete asset {$asset->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($created['collections'] as $collection) {
        try {
            Plugin::getInstance()->collections->deleteCollectionById((int)$collection->id);
        } catch (Throwable) {
            // Already gone with its parent.
        }
    }

    foreach ($created['folders'] as $folder) {
        try {
            // Assets first: a folder that still holds files will not delete, and leaving it behind
            // is what stops the next run from starting.
            foreach (Asset::find()->folderId($folder->id)->status(null)->trashed(null)->all() as $leftover) {
                $elements->deleteElement($leftover, true);
            }

            Craft::$app->getAssets()->deleteFoldersByIds([$folder->id]);
        } catch (Throwable $e) {
            echo "  ! could not delete folder {$folder->id}: {$e->getMessage()}\n";
        }
    }

    // Leaves the harness on whichever edition it was found on.
    Plugin::getInstance()->edition = $originalEdition;

    echo "  ✓ cleaned up\n";

    echo "\n$passed passed, $failed failed\n";
}

exit($failed > 0 ? 1 : 0);

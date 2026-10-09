<?php
/**
 * Library GraphQL checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-library/tests/integration/graphql.php
 *
 * Every query goes through `Craft::$app->getGql()->executeQuery()` against a real schema. Craft
 * builds the schema definition once per process and cannot rebuild it cleanly, so the parent
 * process makes the fixtures and then runs this same file once per schema in a child process
 * (`graphql.php case <name> <fixtures-json>`). Each schema gets a fresh UID and GraphQL caching is
 * off, so no case can be answered from another's cached result.
 *
 * Self-cleaning like checks.php: collections and image files are made in a temporary folder on each
 * volume and removed in a `finally` block.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Asset;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use craft\models\VolumeFolder;
use justinholtweb\library\gql\LibraryQueries;
use justinholtweb\library\models\Collection;
use justinholtweb\library\Plugin;

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

$plugin = Plugin::getInstance();

if ($plugin === null) {
    echo "Library is not installed in this site.\n";
    exit(1);
}

// ---------------------------------------------------------------------------------------------
// Child: one schema, one process
// ---------------------------------------------------------------------------------------------

if (($argv[1] ?? null) === 'case') {
    $case = $argv[2];
    $f = json_decode($argv[3], true);

    // The collection component is offered on every edition: GraphQL is a Lite feature.
    $plugin->edition = $f['edition'];
    Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = false;

    $sites = array_map(fn($s) => "sites.$s->uid:read", Craft::$app->getSites()->getAllSites());
    $volumeScopes = array_map(fn($uid) => "volumes.$uid:read", $f['scopeVolumes']);
    $scope = array_merge($sites, $volumeScopes, $f['component'] ? [LibraryQueries::COMPONENT . ':read'] : []);
    $schema = new GqlSchema(['name' => "Library $case", 'scope' => $scope, 'uid' => StringHelper::UUID()]);

    $gql = fn(string $query) => Craft::$app->getGql()->executeQuery($schema, $query);
    $ids = function(array $result, string $key = 'assets'): array {
        $ids = array_map(fn($a) => (int)$a['id'], $result['data'][$key] ?? []);
        sort($ids);
        return $ids;
    };
    $expect = function(array $actual, array $expected): bool|string {
        sort($expected);
        return $actual === $expected ?: 'got [' . implode(', ', $actual) . '], expected [' . implode(', ', $expected) . ']';
    };
    $a = $f['assets'];
    $c = $f['collections'];

    echo "\n$case\n";

    switch ($case) {
        case 'none':
            check('without “Query for collections” there is no libraryCollections query', function() use ($gql) {
                $result = $gql('{ libraryCollections { handle } }');

                // Craft drops a root field the schema lacks: assert on the data being absent.
                return !array_key_exists('libraryCollections', $result['data'] ?? []) ?: json_encode($result);
            });

            check('…and asset queries do not take libraryCollection', function() use ($gql, $c, $a) {
                $result = $gql("{ assets(libraryCollection: \"{$c['photos']['handle']}\") { id } }");
                $leaked = in_array($a['photo'], array_map(fn($x) => (int)$x['id'], $result['data']['assets'] ?? []), true);

                return !$leaked && !empty($result['errors']) ?: json_encode($result);
            });

            check('…while plain asset queries still work', function() use ($gql, $ids, $a) {
                $result = $gql("{ assets(id: [{$a['photo']}]) { id } }");

                return $ids($result) === [$a['photo']] ?: json_encode($result);
            });

            check('the schema component is offered under Library', function() {
                Craft::$app->getGql()->flushCaches();
                $queries = Craft::$app->getGql()->getAllSchemaComponents()['queries'] ?? [];

                return isset($queries['Library'][LibraryQueries::COMPONENT . ':read'])
                    ?: 'not among: ' . implode(', ', array_keys($queries));
            });
            break;

        case 'full':
            check('libraryCollections lists the tree in order, parents before their children', function() use ($gql, $c) {
                $result = $gql('{ libraryCollections { id handle level parentId parent { handle } children { handle } } }');
                $mine = array_values(array_filter(
                    $result['data']['libraryCollections'] ?? [],
                    fn($x) => in_array($x['handle'], [$c['brand']['handle'], $c['logos']['handle'], $c['photos']['handle']], true),
                ));
                $byHandle = array_column($mine, null, 'handle');
                $order = array_column($mine, 'handle');
                $brand = $byHandle[$c['brand']['handle']] ?? null;
                $logos = $byHandle[$c['logos']['handle']] ?? null;

                return count($mine) === 3
                    && array_search($c['brand']['handle'], $order, true) + 1 === array_search($c['logos']['handle'], $order, true)
                    && $brand['level'] === 1 && $brand['parent'] === null
                    && in_array($c['logos']['handle'], array_column($brand['children'], 'handle'), true)
                    && $logos['level'] === 2 && $logos['parentId'] === $c['brand']['id']
                    && $logos['parent']['handle'] === $c['brand']['handle']
                    ?: json_encode($result);
            });

            check('topLevel leaves nested collections to `children`', function() use ($gql, $c) {
                $result = $gql('{ libraryCollections(topLevel: true) { handle children { handle } } }');
                $handles = array_column($result['data']['libraryCollections'] ?? [], 'handle');

                return in_array($c['brand']['handle'], $handles, true) && !in_array($c['logos']['handle'], $handles, true)
                    ?: json_encode($result);
            });

            check('handle, uid and id narrow the list', function() use ($gql, $c) {
                $byHandle = $gql("{ libraryCollections(handle: \"{$c['photos']['handle']}\") { uid } }");
                $byUid = $gql("{ libraryCollections(uid: \"{$c['logos']['uid']}\") { handle } }");
                $byId = $gql("{ libraryCollections(id: [{$c['brand']['id']}]) { handle } }");
                $nothing = $gql('{ libraryCollections(handle: "no-such-collection") { handle } }');

                return array_column($byHandle['data']['libraryCollections'] ?? [], 'uid') === [$c['photos']['uid']]
                    && array_column($byUid['data']['libraryCollections'] ?? [], 'handle') === [$c['logos']['handle']]
                    && array_column($byId['data']['libraryCollections'] ?? [], 'handle') === [$c['brand']['handle']]
                    && ($nothing['data']['libraryCollections'] ?? null) === []
                    ?: json_encode([$byHandle, $byUid, $byId, $nothing]);
            });

            check('assets(libraryCollection:) filters by handle', function() use ($gql, $ids, $expect, $c, $a) {
                return $expect($ids($gql("{ assets(libraryCollection: \"{$c['photos']['handle']}\") { id } }")), [$a['photo']]);
            });

            check('a parent collection includes the collections nested inside it', function() use ($gql, $ids, $expect, $c, $f) {
                return $expect($ids($gql("{ assets(libraryCollection: \"{$c['brand']['handle']}\") { id } }")), $f['logoIds']);
            });

            check('several collections, by handle and by UID', function() use ($gql, $ids, $expect, $c, $a, $f) {
                $result = $gql("{ assets(libraryCollection: [\"{$c['logos']['uid']}\", \"{$c['photos']['handle']}\"]) { id } }");

                return $expect($ids($result), array_merge($f['logoIds'], [$a['photo']]));
            });

            check('an unknown handle matches nothing, not the whole library', function() use ($gql) {
                $result = $gql('{ assets(libraryCollection: "no-such-collection") { id } }');

                return ($result['data']['assets'] ?? null) === [] ?: json_encode($result);
            });

            check('an empty list is no filter, the same as leaving it out', function() use ($gql, $ids, $a) {
                $result = $gql("{ assets(libraryCollection: [], id: [{$a['unfiled']}]) { id } }");

                return $ids($result) === [$a['unfiled']] ?: json_encode($result);
            });

            check('it combines with Craft’s own arguments', function() use ($gql, $ids, $expect, $c, $a) {
                $inside = $gql("{ assets(libraryCollection: \"{$c['brand']['handle']}\", id: [{$a['logo']}, {$a['photo']}]) { id } }");
                $outside = $gql("{ assets(libraryCollection: \"{$c['photos']['handle']}\", id: [{$a['logo']}]) { id } }");

                return $expect($ids($inside), [$a['logo']]) === true && ($outside['data']['assets'] ?? null) === []
                    ?: json_encode([$inside, $outside]);
            });

            check('assetCount and asset take it too', function() use ($gql, $c, $a, $f) {
                $count = $gql("{ assetCount(libraryCollection: \"{$c['brand']['handle']}\") }");
                $one = $gql("{ asset(libraryCollection: \"{$c['photos']['handle']}\") { id } }");

                return ($count['data']['assetCount'] ?? null) === count($f['logoIds'])
                    && (int)($one['data']['asset']['id'] ?? 0) === $a['photo']
                    ?: json_encode([$count, $one]);
            });

            check('a collection’s assetCount includes nested collections', function() use ($gql, $c, $f) {
                $result = $gql("{ libraryCollections(handle: [\"{$c['brand']['handle']}\", \"{$c['photos']['handle']}\"]) { handle assetCount } }");
                $counts = array_column($result['data']['libraryCollections'] ?? [], 'assetCount', 'handle');

                return ($counts[$c['brand']['handle']] ?? null) === count($f['logoIds'])
                    && ($counts[$c['photos']['handle']] ?? null) === 1
                    ?: json_encode($result);
            });

            check('the argument is in the schema, documented', function() use ($gql) {
                $result = $gql('{ __type(name: "Query") { fields { name args { name } } } }');
                $fields = array_column($result['data']['__type']['fields'] ?? [], 'args', 'name');
                $has = fn(string $q) => in_array('libraryCollection', array_column($fields[$q] ?? [], 'name'), true);

                return $has('assets') && $has('asset') && $has('assetCount') ?: json_encode(array_keys($fields));
            });
            break;

        case 'scoped':
            // Allowed to read collections and the first volume only. The logo in the second volume
            // is in the collection, and must not come back through it or through the count.
            check('a collection spanning volumes only yields files in the schema’s volumes', function() use ($gql, $ids, $c, $a) {
                return $ids($gql("{ assets(libraryCollection: \"{$c['brand']['handle']}\") { id } }")) === [$a['logo']]
                    ?: 'the other volume’s file leaked';
            });

            check('…and the count says the same', function() use ($gql, $c) {
                $count = $gql("{ assetCount(libraryCollection: \"{$c['brand']['handle']}\") }");
                $field = $gql("{ libraryCollections(handle: \"{$c['brand']['handle']}\") { assetCount } }");

                return ($count['data']['assetCount'] ?? null) === 1
                    && ($field['data']['libraryCollections'][0]['assetCount'] ?? null) === 1
                    ?: json_encode([$count, $field]);
            });
            break;

        case 'elsewhere':
            // Allowed to read collections and assets, but in a volume none of the fixtures are in.
            // With one volume on the site this stands in for the cross-volume case: the collection
            // must not widen Craft's own volume limit.
            check('a collection does not reach past the schema’s volumes', function() use ($gql, $c) {
                $assets = $gql("{ assets(libraryCollection: \"{$c['brand']['handle']}\") { id } }");
                $count = $gql("{ assetCount(libraryCollection: \"{$c['brand']['handle']}\") }");
                $field = $gql("{ libraryCollections(handle: \"{$c['brand']['handle']}\") { assetCount } }");

                return ($assets['data']['assets'] ?? null) === []
                    && ($count['data']['assetCount'] ?? null) === 0
                    && ($field['data']['libraryCollections'][0]['assetCount'] ?? null) === 0
                    ?: json_encode([$assets, $count, $field]);
            });
            break;

        case 'novolumes':
            check('collections without any volume: the list works, counts are 0, assets are absent', function() use ($gql, $c) {
                $list = $gql("{ libraryCollections(handle: \"{$c['brand']['handle']}\") { handle assetCount } }");
                $assets = $gql("{ assets(libraryCollection: \"{$c['brand']['handle']}\") { id } }");

                return ($list['data']['libraryCollections'][0]['assetCount'] ?? null) === 0
                    && !array_key_exists('assets', $assets['data'] ?? [])
                    ?: json_encode([$list, $assets]);
            });
            break;
    }

    echo "RESULT $passed $failed\n";
    exit(0);
}

// ---------------------------------------------------------------------------------------------
// Parent: fixtures, one child per schema, teardown
// ---------------------------------------------------------------------------------------------

$created = ['collections' => [], 'assets' => [], 'folders' => []];
$assetsService = Craft::$app->getAssets();
$elements = Craft::$app->getElements();

function testFolder(craft\models\Volume $volume): VolumeFolder
{
    global $assetsService, $created;

    $rootFolder = $assetsService->getRootFolderByVolumeId($volume->id);
    $folder = $assetsService->findFolder(['parentId' => $rootFolder->id, 'name' => 'library-gql-checks']);

    if ($folder === null) {
        $folder = new VolumeFolder([
            'parentId' => $rootFolder->id,
            'volumeId' => $volume->id,
            'name' => 'library-gql-checks',
            'path' => 'library-gql-checks/',
        ]);
        $assetsService->createFolder($folder, true);
    }

    foreach (Asset::find()->folderId($folder->id)->status(null)->trashed(null)->all() as $stale) {
        Craft::$app->getElements()->deleteElement($stale, true);
    }

    $created['folders'][] = $folder;

    return $folder;
}

function makeAsset(VolumeFolder $folder, string $filename): Asset
{
    global $created;

    $tmp = Craft::$app->getPath()->getTempPath() . '/' . uniqid('libgql', true) . '.png';
    $image = imagecreatetruecolor(4, 4);
    imagefill($image, 0, 0, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), 128));
    imagepng($image, $tmp);
    imagedestroy($image);

    $asset = new Asset();
    $asset->tempFilePath = $tmp;
    $asset->setFilename($filename);
    $asset->newFolderId = $folder->id;
    $asset->setVolumeId($folder->volumeId);
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
        'handle' => 'libgql' . substr(md5($name . microtime()), 0, 8),
        'parentId' => $parentId,
    ]);

    if (!Plugin::getInstance()->collections->saveCollection($collection)) {
        throw new RuntimeException("Could not save collection $name: " . implode(' ', $collection->getFirstErrors()));
    }

    $created['collections'][] = $collection;

    return $collection;
}

$totalPassed = 0;
$totalFailed = 0;

try {
    echo "Library GraphQL checks\n";

    $volumes = Craft::$app->getVolumes()->getAllVolumes();

    if (!$volumes) {
        echo "This site has no asset volumes, so there is nothing to test against.\n";
        exit(1);
    }

    $first = $volumes[0];
    $second = $volumes[1] ?? null;
    $folderA = testFolder($first);

    $brand = makeCollection('Libgql Brand');
    $logos = makeCollection('Libgql Logos', $brand->id);
    $photos = makeCollection('Libgql Photos');

    $logo = makeAsset($folderA, 'libgql-logo.png');
    $photo = makeAsset($folderA, 'libgql-photo.png');
    $unfiled = makeAsset($folderA, 'libgql-unfiled.png');
    $logoIds = [(int)$logo->id];

    $membership = $plugin->membership;
    $membership->assign([$logo->id], [$logos->id]);
    $membership->assign([$photo->id], [$photos->id]);

    $otherLogo = null;

    if ($second !== null) {
        $otherLogo = makeAsset(testFolder($second), 'libgql-logo-b.png');
        $membership->assign([$otherLogo->id], [$logos->id]);
        $logoIds[] = (int)$otherLogo->id;
    }

    $collection = fn(Collection $x) => ['id' => (int)$x->id, 'uid' => $x->uid, 'handle' => $x->handle];
    $fixtures = [
        // Lite: nothing here is a Pro feature, so the run proves it.
        'edition' => Plugin::EDITION_LITE,
        'collections' => ['brand' => $collection($brand), 'logos' => $collection($logos), 'photos' => $collection($photos)],
        'assets' => [
            'logo' => (int)$logo->id,
            'photo' => (int)$photo->id,
            'unfiled' => (int)$unfiled->id,
        ],
        'logoIds' => $logoIds,
    ];
    $allVolumeUids = array_map(fn($v) => $v->uid, $volumes);

    $cases = [
        'none' => ['component' => false, 'scopeVolumes' => $allVolumeUids],
        'full' => ['component' => true, 'scopeVolumes' => $allVolumeUids],
        'novolumes' => ['component' => true, 'scopeVolumes' => []],
    ];

    if ($second !== null) {
        $cases['scoped'] = ['component' => true, 'scopeVolumes' => [$first->uid]];
    } else {
        echo "  (only one volume: the cross-volume case runs against a volume the schema names but no fixture is in)\n";
    }

    // A volume UID that matches no volume: the schema may query assets, just none of these.
    $cases['elsewhere'] = ['component' => true, 'scopeVolumes' => [StringHelper::UUID()]];

    foreach ($cases as $name => $config) {
        $json = json_encode(array_merge($fixtures, $config));
        $output = [];
        exec(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' case ' . escapeshellarg($name) . ' ' . escapeshellarg($json) . ' 2>&1', $output, $code);

        $result = null;

        foreach ($output as $line) {
            if (preg_match('/^RESULT (\d+) (\d+)$/', $line, $m)) {
                $result = [(int)$m[1], (int)$m[2]];
                continue;
            }

            echo $line . "\n";
        }

        if ($result === null) {
            $totalFailed++;
            echo "  ✗ case $name did not finish (exit $code)\n";
            continue;
        }

        $totalPassed += $result[0];
        $totalFailed += $result[1];
    }
} finally {
    echo "\nTeardown\n";

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

    foreach (array_reverse($created['collections']) as $collection) {
        try {
            Plugin::getInstance()->collections->deleteCollectionById((int)$collection->id);
        } catch (Throwable) {
            // Already gone with its parent.
        }
    }

    foreach ($created['folders'] as $folder) {
        try {
            Craft::$app->getAssets()->deleteFoldersByIds([$folder->id]);
        } catch (Throwable $e) {
            echo "  ! could not delete folder {$folder->id}: {$e->getMessage()}\n";
        }
    }

    echo "  ✓ cleaned up\n";
    echo "\n$totalPassed passed, $totalFailed failed\n";
}

exit($totalFailed > 0 ? 1 : 0);

<?php

declare(strict_types=1);

namespace justinholtweb\library\gql\types;

use Craft;
use craft\elements\Asset;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use justinholtweb\library\gql\LibraryQueries;
use justinholtweb\library\models\Collection;
use justinholtweb\library\Plugin;

/**
 * The GraphQL shape of a Library collection.
 *
 * The same fields `craft.library.collection()` gives a template, plus `level`, `parent` and an
 * `assetCount` that only counts files in volumes the schema can read.
 */
abstract class CollectionType
{
    public const NAME = 'LibraryCollection';

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn() => new ObjectType([
            'name' => self::NAME,
            'description' => 'A Library collection: a cross-volume grouping of assets, nested to any depth.',
            'fields' => fn() => [
                'id' => Type::nonNull(Type::int()),
                'uid' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'handle' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'What the `libraryCollection` argument on asset queries takes.',
                ],
                'description' => Type::string(),
                'color' => [
                    'type' => Type::string(),
                    'description' => 'The colour picked for the collection in the control panel, or null.',
                ],
                'parentId' => Type::int(),
                'level' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => '1 for a top-level collection, 2 for one nested inside it, and so on.',
                    'resolve' => fn(Collection $collection) => self::level($collection),
                ],
                'parent' => [
                    'type' => self::getType(),
                    'description' => 'The collection this one is nested inside, or null.',
                    'resolve' => fn(Collection $collection) => $collection->parentId !== null
                        ? Plugin::getInstance()->collections->getCollectionById($collection->parentId)
                        : null,
                ],
                'children' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::getType()))),
                    'description' => 'The collections nested directly inside this one.',
                    'resolve' => fn(Collection $collection) => self::children($collection),
                ],
                'assetCount' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'Assets filed in this collection or any nested inside it, in volumes this schema can read.',
                    'resolve' => fn(Collection $collection) => self::assetCount($collection),
                ],
            ],
        ]));
    }

    /** @return Collection[] */
    private static function children(Collection $collection): array
    {
        // Nodes from the tree walk already carry their children; one fetched by ID does not.
        if ($collection->children) {
            return $collection->children;
        }

        $collections = Plugin::getInstance()->collections;

        return array_values(array_filter(
            $collections->getAllCollections(),
            fn(Collection $c) => $c->parentId !== null && $c->parentId === $collection->id,
        ));
    }

    private static function level(Collection $collection): int
    {
        $collections = Plugin::getInstance()->collections;
        $level = 1;
        $seen = [];
        $parentId = $collection->parentId;

        // The seen-list is a cycle guard, the same one the tree walk has.
        while ($parentId !== null && !isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $parent = $collections->getCollectionById($parentId);

            if ($parent === null) {
                break;
            }

            $level++;
            $parentId = $parent->parentId;
        }

        return $level;
    }

    /**
     * Counted the way Craft's own `assetCount` query would count `assets(libraryCollection: …)`:
     * not trashed, and only in volumes the schema can read. A
     * count across every volume would tell a schema about files it was not given.
     */
    private static function assetCount(Collection $collection): int
    {
        if ($collection->id === null || !GqlHelper::canQueryAssets()) {
            return 0;
        }

        $volumes = Craft::$app->getVolumes();
        $volumeIds = array_values(array_filter(array_map(
            fn(string $uid) => $volumes->getVolumeByUid($uid)?->id,
            GqlHelper::extractAllowedEntitiesFromSchema('read')['volumes'] ?? [],
        )));

        if (!$volumeIds) {
            return 0;
        }

        $collectionIds = Plugin::getInstance()->collections->getIdWithDescendants($collection->id);

        return (int)Asset::find()
            ->volumeId($volumeIds)
            ->andWhere(LibraryQueries::membershipCondition($collectionIds))
            ->count();
    }
}

<?php

declare(strict_types=1);

namespace justinholtweb\library\gql;

use craft\db\Query as DbQuery;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use justinholtweb\library\gql\types\CollectionType;
use justinholtweb\library\models\Collection;
use justinholtweb\library\Plugin;
use justinholtweb\library\records\CollectionAssetRecord;

/**
 * Collections for headless front ends: the `libraryCollections` query, and a `libraryCollection`
 * argument on Craft's own `assets`, `asset` and `assetCount` queries.
 *
 * Both sit behind one schema component, “Query for collections”. Without it the query does not
 * exist and the argument is not offered — a collection's members say something about the files in
 * it, so filtering by one is reading it.
 *
 * The argument narrows Craft's query rather than replacing it. Craft's asset resolver still runs,
 * still applies every other argument and still limits the result to the volumes the schema can
 * read, so a collection that spans volumes only ever yields the files the schema was given.
 */
class LibraryQueries extends Query
{
    /** The schema component that switches collections on. */
    public const COMPONENT = 'libraryCollections.all';

    /** The argument added to Craft's asset queries. */
    public const ARGUMENT = 'libraryCollection';

    /** Craft's asset queries that take the argument. Nested asset fields are not among them. */
    public const ASSET_QUERIES = ['assets', 'asset', 'assetCount'];

    /**
     * @inheritdoc
     */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !self::canQuery()) {
            return [];
        }

        return [
            'libraryCollections' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(CollectionType::getType()))),
                'args' => [
                    'handle' => [
                        'type' => Type::listOf(Type::string()),
                        'description' => 'Narrows the results to collections with these handles.',
                    ],
                    'uid' => [
                        'type' => Type::listOf(Type::string()),
                        'description' => 'Narrows the results to collections with these UIDs.',
                    ],
                    'id' => [
                        'type' => Type::listOf(Type::int()),
                        'description' => 'Narrows the results to collections with these IDs.',
                    ],
                    'topLevel' => [
                        'type' => Type::boolean(),
                        'description' => 'Only the top-level collections. Their nested collections are under `children`.',
                    ],
                ],
                'resolve' => static fn($root, array $args) => self::resolveCollections($args),
                'description' => 'Library collections, in tree order: each parent followed by the collections nested inside it.',
            ],
        ];
    }

    /** Whether the active schema may read collections. */
    public static function canQuery(): bool
    {
        return GqlHelper::canSchema(self::COMPONENT, 'read');
    }

    /**
     * Adds the `libraryCollection` argument to whichever of Craft's asset queries the schema has.
     *
     * Craft hands every query argument to the element query as a method call, and an asset query
     * has no `libraryCollection()` method — plugins cannot add one. So the resolver is wrapped: the
     * argument is taken off and turned into a condition before Craft's own resolver sees the rest.
     *
     * @param array<string,array> $queries
     * @return array<string,array>
     */
    public static function addAssetArguments(array $queries): array
    {
        if (!self::canQuery()) {
            return $queries;
        }

        foreach (self::ASSET_QUERIES as $name) {
            if (!isset($queries[$name]['resolve'])) {
                continue;
            }

            $queries[$name]['args'][self::ARGUMENT] = [
                'name' => self::ARGUMENT,
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the query results to assets filed in these Library collections, by handle or UID. Collections nested inside them are included.',
            ];

            $resolve = $queries[$name]['resolve'];
            $queries[$name]['resolve'] = static fn(mixed $source, array $arguments, mixed $context, ResolveInfo $info) => call_user_func(
                $resolve,
                $source,
                self::applyCollectionArgument($arguments),
                $context,
                $info,
            );
        }

        return $queries;
    }

    /**
     * Swaps `libraryCollection` for the condition it stands for.
     *
     * No value, or an empty list, is no filter — the same as leaving the argument out. A handle that
     * matches no collection is a filter that matches nothing: answering a typo with the whole library
     * is the failure mode this plugin is careful about everywhere else.
     */
    public static function applyCollectionArgument(array $arguments): array
    {
        if (!array_key_exists(self::ARGUMENT, $arguments)) {
            return $arguments;
        }

        $values = $arguments[self::ARGUMENT];
        unset($arguments[self::ARGUMENT]);

        if ($values === null || $values === [] || $values === '') {
            return $arguments;
        }

        $condition = self::membershipCondition(self::collectionIds((array)$values));

        $arguments['andWhere'] = isset($arguments['andWhere'])
            ? ['and', $arguments['andWhere'], $condition]
            : $condition;

        return $arguments;
    }

    /**
     * The given collections plus everything nested inside them, from handles or UIDs.
     *
     * @param array<mixed> $values
     * @return int[]
     */
    public static function collectionIds(array $values): array
    {
        $collections = Plugin::getInstance()->collections;
        $ids = [];

        foreach ($values as $value) {
            $value = trim((string)$value);

            if ($value === '') {
                continue;
            }

            $collection = $collections->getCollectionByHandle($value) ?? $collections->getCollectionByUid($value);

            if ($collection === null || $collection->id === null) {
                continue;
            }

            foreach ($collections->getIdWithDescendants($collection->id) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * A condition for an asset query: “filed in one of these collections”.
     *
     * A correlated `EXISTS` on `elements.id`, which is how the Collection condition rule filters
     * too. Conditions added to an element query land on its subquery, where `elements` is joined.
     *
     * @param int[] $collectionIds
     */
    public static function membershipCondition(array $collectionIds): array|string
    {
        if (!$collectionIds) {
            return '0 = 1';
        }

        return ['exists', (new DbQuery())
            ->from(['lca' => CollectionAssetRecord::TABLE])
            ->where('[[lca.assetId]] = [[elements.id]]')
            ->andWhere(['lca.collectionId' => $collectionIds])];
    }

    /**
     * @param array{handle?: ?array, uid?: ?array, id?: ?array, topLevel?: ?bool} $args
     * @return Collection[]
     */
    public static function resolveCollections(array $args): array
    {
        $tree = Plugin::getInstance()->collections->getTree();
        $topLevel = !empty($args['topLevel']);

        // Tree order, flattened, unless only the roots were asked for. Each node carries its
        // `children`, so a front end can take the flat list or walk the tree from the roots.
        $list = [];
        $walk = function(array $branch) use (&$walk, &$list, $topLevel): void {
            foreach ($branch as $collection) {
                /** @var Collection $collection */
                $list[] = $collection;

                if (!$topLevel) {
                    $walk($collection->children);
                }
            }
        };
        $walk($tree);

        $handles = self::stringList($args['handle'] ?? null);
        $uids = self::stringList($args['uid'] ?? null);
        $ids = isset($args['id']) ? array_map('intval', (array)$args['id']) : null;

        return array_values(array_filter($list, fn(Collection $c) =>
            ($handles === null || in_array($c->handle, $handles, true)) &&
            ($uids === null || in_array($c->uid, $uids, true)) &&
            ($ids === null || in_array((int)$c->id, $ids, true))
        ));
    }

    /** @return string[]|null Null when the argument was not given, so “not given” and “empty” differ. */
    private static function stringList(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return array_map('strval', (array)$value);
    }
}

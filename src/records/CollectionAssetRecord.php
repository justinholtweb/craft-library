<?php

declare(strict_types=1);

namespace justinholtweb\library\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $collectionId
 * @property int $assetId
 * @property int|null $sortOrder
 */
class CollectionAssetRecord extends ActiveRecord
{
    public const TABLE = '{{%library_collection_assets}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}

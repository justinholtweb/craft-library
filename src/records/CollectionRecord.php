<?php

declare(strict_types=1);

namespace justinholtweb\library\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property int|null $parentId
 * @property string|null $description
 * @property string|null $color
 * @property int $sortOrder
 * @property string $uid
 */
class CollectionRecord extends ActiveRecord
{
    public const TABLE = '{{%library_collections}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}

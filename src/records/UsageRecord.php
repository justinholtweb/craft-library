<?php

declare(strict_types=1);

namespace justinholtweb\library\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $assetId
 * @property int $elementId
 * @property string $elementType
 * @property int|null $siteId
 * @property string|null $fieldHandle
 * @property string $source
 */
class UsageRecord extends ActiveRecord
{
    public const TABLE = '{{%library_usage}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}

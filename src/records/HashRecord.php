<?php

declare(strict_types=1);

namespace justinholtweb\library\records;

use craft\db\ActiveRecord;

/**
 * @property int $assetId
 * @property int $size
 * @property string $hash
 */
class HashRecord extends ActiveRecord
{
    public const TABLE = '{{%library_hashes}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}

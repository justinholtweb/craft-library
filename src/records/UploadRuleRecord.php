<?php

declare(strict_types=1);

namespace justinholtweb\library\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property bool $enabled
 * @property int $sortOrder
 * @property int|null $volumeId
 * @property string|null $folderPattern
 * @property string|null $kinds
 * @property string|null $filenamePattern
 * @property string|null $collectionIds
 * @property string|null $altSource
 * @property string $uid
 */
class UploadRuleRecord extends ActiveRecord
{
    public const TABLE = '{{%library_uploadrules}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}

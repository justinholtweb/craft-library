<?php

declare(strict_types=1);

namespace justinholtweb\library\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use justinholtweb\library\Plugin;
use justinholtweb\library\records\CollectionRecord;

/**
 * A media collection — Library's answer to WordPress media categories.
 *
 * Collections are content, not schema: editors make them constantly, so they live in plain
 * database tables and never touch project config.
 */
class Collection extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?int $parentId = null;
    public ?string $description = null;
    public ?string $color = null;
    public int $sortOrder = 0;
    public ?string $uid = null;

    /** Populated by the collections service when it is asked for counts. */
    public ?int $assetCount = null;

    /** @var Collection[] Populated when the tree is requested. */
    public array $children = [];

    public function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid', 'title']],
            [['handle'], UniqueValidator::class, 'targetClass' => CollectionRecord::class, 'targetAttribute' => 'handle'],
            [['description'], 'string'],
            [['color'], 'in', 'range' => self::colorOptions(), 'skipOnEmpty' => true],
            [['sortOrder'], 'integer', 'min' => 0],
            [['parentId'], 'integer'],
            [['parentId'], 'validateParent'],
        ];
    }

    /**
     * A collection cannot be its own parent, nor a descendant of itself. Without this a two-row
     * cycle makes every tree walk in the plugin loop forever.
     */
    public function validateParent(): void
    {
        if ($this->parentId === null) {
            return;
        }

        if ($this->id !== null && $this->parentId === $this->id) {
            $this->addError('parentId', Craft::t('library', 'A collection cannot be its own parent.'));
            return;
        }

        if ($this->id === null) {
            return;
        }

        $collections = Plugin::getInstance()->collections;
        $seen = [];
        $ancestorId = $this->parentId;

        while ($ancestorId !== null) {
            if ($ancestorId === $this->id) {
                $this->addError('parentId', Craft::t('library', 'A collection cannot be nested inside one of its own children.'));
                return;
            }

            // Guards against a pre-existing cycle in the data rather than the one being created.
            if (isset($seen[$ancestorId])) {
                return;
            }
            $seen[$ancestorId] = true;

            $ancestorId = $collections->getCollectionById($ancestorId)?->parentId;
        }
    }

    /** Craft's own status-dot palette, so a collection chip looks native. */
    public static function colorOptions(): array
    {
        return [
            'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal',
            'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose', 'gray',
        ];
    }

    public function suggestHandle(): void
    {
        if ($this->handle === '' && $this->name !== '') {
            $this->handle = StringHelper::toHandle($this->name);
        }
    }

    public function getCpEditUrl(): string
    {
        return 'library/collections/' . ($this->id ?? 'new');
    }

    public function __toString(): string
    {
        return $this->name;
    }
}

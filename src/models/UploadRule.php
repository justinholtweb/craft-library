<?php

declare(strict_types=1);

namespace justinholtweb\library\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use justinholtweb\library\models\Settings;

/**
 * A rule that files a newly uploaded asset into collections, and optionally seeds its alt text.
 *
 * Every match column is optional. A rule with none of them set matches every upload, which is
 * how "put everything in Unsorted" is expressed.
 */
class UploadRule extends Model
{
    public ?int $id = null;
    public string $name = '';
    public bool $enabled = true;
    public int $sortOrder = 0;
    public ?int $volumeId = null;
    public ?string $folderPattern = null;

    /** @var string[] Asset kinds (`Asset::KIND_*`) this rule applies to. Empty means all. */
    public array $kinds = [];

    public ?string $filenamePattern = null;

    /** @var int[] */
    public array $collectionIds = [];

    public ?string $altSource = null;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['enabled'], 'boolean'],
            [['sortOrder'], 'integer', 'min' => 0],
            [['volumeId'], 'integer'],
            [['folderPattern', 'filenamePattern'], 'string', 'max' => 255],
            [['kinds', 'collectionIds'], 'safe'],
            [['altSource'], 'in', 'range' => [
                Settings::ALT_SOURCE_NONE,
                Settings::ALT_SOURCE_FILENAME,
                Settings::ALT_SOURCE_TITLE,
            ], 'skipOnEmpty' => true],
            // skipOnEmpty: false, or Yii never runs this. An inline validator is skipped when the
            // attribute it hangs off is empty — and an empty `collectionIds` is precisely the case
            // this rule exists to catch.
            [['collectionIds'], 'validateHasEffect', 'skipOnEmpty' => false],
        ];
    }

    /**
     * A rule that assigns no collections and sets no alt text would run on every upload and do
     * nothing, which reads as a bug in the field rather than an empty form.
     */
    public function validateHasEffect(): void
    {
        $setsAlt = $this->altSource !== null && $this->altSource !== Settings::ALT_SOURCE_NONE;

        if (empty($this->collectionIds) && !$setsAlt) {
            $this->addError('collectionIds', Craft::t('library', 'Choose at least one collection, or set an alt text source.'));
        }
    }

    public function attributeLabels(): array
    {
        return [
            'name' => Craft::t('library', 'Name'),
            'volumeId' => Craft::t('library', 'Volume'),
            'folderPattern' => Craft::t('library', 'Folder path'),
            'kinds' => Craft::t('library', 'File kinds'),
            'filenamePattern' => Craft::t('library', 'Filename'),
            'collectionIds' => Craft::t('library', 'Collections'),
            'altSource' => Craft::t('library', 'Alt text'),
        ];
    }

    /** Whether this rule should run for the given asset. */
    public function matches(Asset $asset): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->volumeId !== null && $asset->volumeId !== $this->volumeId) {
            return false;
        }

        if (!empty($this->kinds) && !in_array($asset->kind, $this->kinds, true)) {
            return false;
        }

        if ($this->folderPattern !== null && $this->folderPattern !== '') {
            $path = trim((string)$asset->folderPath, '/');
            if (!self::globMatch($this->folderPattern, $path)) {
                return false;
            }
        }

        if ($this->filenamePattern !== null && $this->filenamePattern !== '') {
            if (!self::globMatch($this->filenamePattern, (string)$asset->filename)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Case-insensitive `*`/`?` glob matching.
     *
     * Built by hand rather than with `fnmatch()`, which is not available on every host and treats
     * `/` specially on some of them — a folder pattern like `brand/*` has to be able to match.
     */
    public static function globMatch(string $pattern, string $subject): bool
    {
        $regex = '';

        foreach (preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $regex .= match ($char) {
                '*' => '.*',
                '?' => '.',
                default => preg_quote($char, '/'),
            };
        }

        return (bool)preg_match('/^' . $regex . '$/iu', $subject);
    }
}

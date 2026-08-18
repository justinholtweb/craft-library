<?php

declare(strict_types=1);

namespace justinholtweb\library\services;

use Craft;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\helpers\StringHelper;
use justinholtweb\library\models\Settings;
use justinholtweb\library\Plugin;
use yii\base\Component;

/**
 * Alt — the accessibility half of the library. Finds images with no alternative text, and fills
 * it in from what is already known about the file.
 *
 * Only images are ever counted: a PDF with no alt text is not an accessibility problem, and
 * including documents in the number makes the number useless.
 */
class Alt extends Component
{
    /** Asset IDs for images with no usable alt text, in the given site. */
    public function getMissingAltAssetIds(?int $siteId = null, ?int $limit = null): array
    {
        $query = $this->missingAltQuery($siteId);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_map('intval', $query->ids());
    }

    public function getMissingAltCount(?int $siteId = null): int
    {
        return (int)$this->missingAltQuery($siteId)->count();
    }

    /**
     * Images with no usable alternative text, as an asset query.
     *
     * Built on Craft's own `hasAlt` param rather than on SQL of my own. `alt` lives in two places
     * — a default on `assets` and a per-site override on `assets_sites` — and the precedence
     * between them (a blank override *hides* a set default) is Craft's rule to define, not this
     * plugin's to guess at. Reimplementing it is how the count silently drifts from what the CP
     * shows on the asset itself.
     */
    public function missingAltQuery(?int $siteId = null): AssetQuery
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $excluded = Plugin::getInstance()->getSettings()->getExcludedVolumeIds();

        $query = Asset::find()
            ->siteId($siteId)
            ->kind(Asset::KIND_IMAGE)
            ->hasAlt(false)
            ->status(null);

        if ($excluded) {
            $query->volumeId(array_map(fn(int $id) => "not $id", $excluded));
        }

        return $query;
    }

    /**
     * Fills in alt text for the given assets from the chosen source, and returns how many changed.
     *
     * Assets that already have alt text are left alone unless `$overwrite` is set — a bulk action
     * that quietly replaces hand-written descriptions with filenames is a data-loss bug.
     *
     * @param Asset[] $assets
     */
    public function fill(array $assets, string $source, bool $overwrite = false, ?int $siteId = null): int
    {
        if ($source === Settings::ALT_SOURCE_NONE) {
            return 0;
        }

        $elementsService = Craft::$app->getElements();
        $changed = 0;

        foreach ($assets as $asset) {
            if ($asset->kind !== Asset::KIND_IMAGE) {
                continue;
            }

            if (!$overwrite && trim((string)$asset->alt) !== '') {
                continue;
            }

            $alt = $this->deriveAlt($asset, $source);

            if ($alt === null || $alt === trim((string)$asset->alt)) {
                continue;
            }

            $asset->alt = $alt;

            // No validation and no propagation: alt text is per-site, so writing it to one site
            // must not stamp the same English string over every other site's translation.
            if ($elementsService->saveElement($asset, false, false)) {
                $changed++;
            }
        }

        return $changed;
    }

    /** Alt text derived from an asset, or null if there is nothing worth writing. */
    public function deriveAlt(Asset $asset, string $source): ?string
    {
        $text = match ($source) {
            Settings::ALT_SOURCE_FILENAME => self::humanizeFilename((string)$asset->filename),
            Settings::ALT_SOURCE_TITLE => (string)$asset->title,
            default => '',
        };

        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    /**
     * Turns `brand-logo_dark-2024_FINAL-v3.png` into `Brand logo dark 2024`.
     *
     * Filenames are a poor source of alt text and this makes no pretence otherwise — it is a
     * starting point a human edits, which still beats an empty attribute. The noise words dropped
     * here are the ones that are always about the file rather than the picture.
     */
    public static function humanizeFilename(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Separators to spaces, then camelCase and digit runs split apart.
        $name = str_replace(['-', '_', '.', '+'], ' ', $name);
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        // `photo`, `image` and `picture` are deliberately *not* here. They read as noise in
        // `photo-1234.jpg`, but `teamPhoto.png` describes a team photo, and "Team photo" is a better
        // description than "Team". The camera and export prefixes below never describe anything.
        $noise = [
            'final', 'copy', 'draft', 'edit', 'edited', 'new', 'old', 'temp', 'tmp', 'untitled',
            'img', 'screenshot', 'export', 'crop', 'cropped',
            'resized', 'small', 'large', 'thumb', 'thumbnail', 'web', 'print', 'hires', 'lowres',
            'v', 'ver', 'version', 'rev', 'scan', 'dsc', 'dscn', 'dcim', 'mvimg', 'pxl',
        ];

        $words = [];

        foreach (explode(' ', $name) as $word) {
            $word = trim($word);

            if ($word === '') {
                continue;
            }

            $bare = strtolower(rtrim($word, '0123456789'));

            if (in_array($bare, $noise, true)) {
                continue;
            }

            // A long bare number is a camera counter or an export id — `IMG_4821` says nothing
            // about what the image shows. A four-digit number in a plausible year range usually
            // does say something ("brand guidelines 2024"), so those are kept, as are short runs
            // like the `01` and `02` in a date. There is no way to tell 4821 from 2024 by shape
            // alone, so this is the line that has to be drawn somewhere.
            if (ctype_digit($word) && strlen($word) >= 4 && !self::looksLikeYear($word)) {
                continue;
            }

            $words[] = $word;
        }

        if (!$words) {
            return '';
        }

        // Sentence case, with acronyms left intact. The camelCase split above manufactures capitals
        // that were never in the filename — `teamPhoto` becomes `team Photo` — so deciding case from
        // the joined string reads that invented capital as the author's intent. Normalising each word
        // instead means `teamPhoto` and `team-photo` land in the same place, while `NASA-image` keeps
        // the part that is genuinely an acronym.
        $words = array_map(
            fn(string $word) => mb_strtoupper($word) === $word ? $word : mb_strtolower($word),
            $words,
        );

        return StringHelper::upperCaseFirst(implode(' ', $words));
    }

    private static function looksLikeYear(string $word): bool
    {
        if (strlen($word) !== 4) {
            return false;
        }

        $value = (int)$word;

        return $value >= 1900 && $value <= 2099;
    }

    /** @return array{images:int,missing:int,described:int} */
    public function getStats(?int $siteId = null): array
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $excluded = Plugin::getInstance()->getSettings()->getExcludedVolumeIds();

        $imagesQuery = Asset::find()
            ->siteId($siteId)
            ->kind(Asset::KIND_IMAGE)
            ->status(null);

        if ($excluded) {
            $imagesQuery->volumeId(array_map(fn(int $id) => "not $id", $excluded));
        }

        $images = (int)$imagesQuery->count();
        $missing = $this->getMissingAltCount($siteId);

        return [
            'images' => $images,
            'missing' => $missing,
            'described' => max(0, $images - $missing),
        ];
    }
}

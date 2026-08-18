<?php

declare(strict_types=1);

namespace justinholtweb\library\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\Db;
use craft\models\VolumeFolder;
use justinholtweb\library\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Renamer — pattern-based bulk renaming, and moving assets between volumes.
 *
 * Every rename is previewed before it runs. Renaming a file is the one bulk action in this plugin
 * that touches bytes on disk rather than rows in a table, and "run it and see" is not an acceptable
 * way to find out that a pattern produced 400 files called `image.jpg`.
 */
class Renamer extends Component
{
    /** Tokens a rename pattern may contain, with a one-line description for the UI. */
    public static function tokens(): array
    {
        return [
            '{basename}' => Craft::t('library', 'Current filename without the extension'),
            '{ext}' => Craft::t('library', 'File extension, without the dot'),
            '{title}' => Craft::t('library', 'Asset title'),
            '{id}' => Craft::t('library', 'Asset ID'),
            '{index}' => Craft::t('library', 'Position in the selection, from 1'),
            '{volume}' => Craft::t('library', 'Volume handle'),
            '{folder}' => Craft::t('library', 'Folder name'),
            '{kind}' => Craft::t('library', 'File kind, e.g. image'),
            '{width}' => Craft::t('library', 'Image width in pixels'),
            '{height}' => Craft::t('library', 'Image height in pixels'),
            '{year}' => Craft::t('library', 'Year the file was uploaded'),
            '{month}' => Craft::t('library', 'Month the file was uploaded, zero padded'),
            '{collection}' => Craft::t('library', 'Handle of the asset’s first collection'),
        ];
    }

    /**
     * Works out what a pattern would do, without doing it.
     *
     * @param Asset[] $assets
     * @return array<int,array{asset:Asset,from:string,to:string,changed:bool,problem:string|null}>
     */
    public function preview(array $assets, string $pattern): array
    {
        $rows = [];

        // Filenames already claimed by this run, so two assets cannot be previewed as both becoming
        // `logo.png` while each looks fine on its own.
        $claimed = [];

        $index = 0;

        foreach ($assets as $asset) {
            $index++;
            $from = (string)$asset->getFilename();
            $problem = null;

            $to = $this->applyPattern($pattern, $asset, $index);

            // The basename, not the whole string: applyPattern() adds the original extension back
            // when the pattern does not mention one, so an empty pattern comes back as ".png" —
            // non-empty, and a file with no name at all.
            if ($to === '' || pathinfo($to, PATHINFO_FILENAME) === '') {
                $problem = Craft::t('library', 'The pattern produced a filename with no name in it.');
                $to = $from;
            } else {
                $to = AssetsHelper::prepareAssetName($to);
            }

            $key = $asset->folderId . '/' . mb_strtolower($to);

            if ($problem === null && isset($claimed[$key])) {
                $problem = Craft::t('library', 'Another asset in this selection would get the same name. Add {index} to the pattern.');
            } elseif ($problem === null && $to !== $from && $this->nameTakenInFolder($asset, $to)) {
                $problem = Craft::t('library', 'A different file in this folder is already called that.');
            }

            $claimed[$key] = true;

            $rows[] = [
                'asset' => $asset,
                'from' => $from,
                'to' => $to,
                'changed' => $to !== $from,
                'problem' => $problem,
            ];
        }

        return $rows;
    }

    /**
     * Runs a rename over the given assets.
     *
     * Rows the preview flagged as a problem are skipped rather than being allowed to fail one at a
     * time — a partially applied bulk rename is much harder to undo than one that refused.
     *
     * @param Asset[] $assets
     * @return array{renamed:int,skipped:int,failed:int,errors:string[]}
     */
    public function rename(array $assets, string $pattern): array
    {
        $result = ['renamed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $assetsService = Craft::$app->getAssets();

        foreach ($this->preview($assets, $pattern) as $row) {
            /** @var Asset $asset */
            $asset = $row['asset'];

            if ($row['problem'] !== null) {
                $result['skipped']++;
                $result['errors'][] = "{$row['from']}: {$row['problem']}";
                continue;
            }

            if (!$row['changed']) {
                $result['skipped']++;
                continue;
            }

            $folder = $asset->getFolder();

            try {
                if ($assetsService->moveAsset($asset, $folder, $row['to'])) {
                    $result['renamed']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = "{$row['from']}: " . implode(' ', $asset->getFirstErrors() ?: [Craft::t('library', 'Could not be renamed.')]);
                }
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = "{$row['from']}: {$e->getMessage()}";
                Craft::warning("Library could not rename asset {$asset->id}: {$e->getMessage()}", Plugin::LOG_CATEGORY);
            }
        }

        return $result;
    }

    /**
     * Moves assets into a folder, which may be in another volume.
     *
     * Craft can move assets within a volume from the index; across volumes it cannot, and doing it
     * by hand means re-uploading and losing every relation pointing at the file. This keeps the
     * element, so nothing that referenced it notices.
     *
     * @param Asset[] $assets
     * @return array{moved:int,skipped:int,failed:int,errors:string[]}
     */
    public function move(array $assets, VolumeFolder $folder): array
    {
        $result = ['moved' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $assetsService = Craft::$app->getAssets();

        foreach ($assets as $asset) {
            if ($asset->folderId === $folder->id) {
                $result['skipped']++;
                continue;
            }

            $filename = (string)$asset->getFilename();

            // A name collision in the target folder is resolved rather than fatal — the whole point
            // of a bulk move is that you are not going to inspect 300 filenames first.
            if ($this->nameTakenInFolder($asset, $filename, $folder->id)) {
                $filename = $this->uniqueNameInFolder($filename, $folder->id);
            }

            try {
                if ($assetsService->moveAsset($asset, $folder, $filename)) {
                    $result['moved']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = "{$asset->getFilename()}: " . implode(' ', $asset->getFirstErrors() ?: [Craft::t('library', 'Could not be moved.')]);
                }
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = "{$asset->getFilename()}: {$e->getMessage()}";
                Craft::warning("Library could not move asset {$asset->id}: {$e->getMessage()}", Plugin::LOG_CATEGORY);
            }
        }

        return $result;
    }

    /** Fills a pattern's tokens in for one asset. */
    public function applyPattern(string $pattern, Asset $asset, int $index = 1): string
    {
        $filename = (string)$asset->getFilename();
        $collections = Plugin::getInstance()->membership->getCollectionsForAsset((int)$asset->id);
        $date = $asset->dateCreated ?? new \DateTime();

        $replacements = [
            '{basename}' => pathinfo($filename, PATHINFO_FILENAME),
            '{ext}' => pathinfo($filename, PATHINFO_EXTENSION),
            '{title}' => (string)$asset->title,
            '{id}' => (string)$asset->id,
            '{index}' => (string)$index,
            '{volume}' => (string)($asset->getVolume()->handle ?? ''),
            '{folder}' => (string)($asset->getFolder()->name ?? ''),
            '{kind}' => (string)$asset->kind,
            '{width}' => (string)($asset->width ?? ''),
            '{height}' => (string)($asset->height ?? ''),
            '{year}' => $date->format('Y'),
            '{month}' => $date->format('m'),
            '{collection}' => $collections[0]->handle ?? '',
        ];

        $result = str_replace(array_keys($replacements), array_values($replacements), $pattern);

        // A pattern that says nothing about the extension keeps the one the file already has,
        // because renaming `photo.jpg` to `photo` makes it undeliverable.
        if (!str_contains($pattern, '{ext}') && pathinfo($result, PATHINFO_EXTENSION) === '') {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);

            if ($ext !== '') {
                $result .= '.' . $ext;
            }
        }

        return trim($result);
    }

    private function nameTakenInFolder(Asset $asset, string $filename, ?int $folderId = null): bool
    {
        return Asset::find()
            ->folderId($folderId ?? $asset->folderId)
            // Db::escapeParam, not a hand-rolled str_replace: Craft also treats a leading `not `,
            // `!=`, `<=` and friends as operators, so a file genuinely named "not final.jpg" would
            // be read as "filename != final.jpg" and match almost everything.
            ->filename(Db::escapeParam($filename))
            ->id($asset->id !== null ? "not $asset->id" : null)
            ->status(null)
            ->exists();
    }

    private function uniqueNameInFolder(string $filename, int $folderId): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $ext !== '' ? '.' . $ext : '';

        for ($i = 1; $i < 100; $i++) {
            $candidate = "$base-$i$suffix";

            $exists = Asset::find()
                ->folderId($folderId)
                ->filename(Db::escapeParam($candidate))
                ->status(null)
                ->exists();

            if (!$exists) {
                return $candidate;
            }
        }

        // Falls back to something guaranteed unique rather than giving up on the move.
        return $base . '-' . bin2hex(random_bytes(4)) . $suffix;
    }
}

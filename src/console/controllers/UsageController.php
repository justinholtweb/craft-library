<?php

declare(strict_types=1);

namespace justinholtweb\library\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\library\Plugin;
use yii\console\ExitCode;

/**
 * Asset usage.
 */
class UsageController extends Controller
{
    /** Show progress while scanning. */
    public bool $progress = true;

    /** How many assets to list. */
    public int $limit = 50;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'scan' => ['progress'],
            'unused' => ['limit'],
            default => [],
        });
    }

    /**
     * Scan all content for asset reference tags that no relation records.
     *
     * The scanner reads every content row that mentions an asset and rebuilds the cached half of
     * usage. Relations are always read live and are unaffected.
     */
    public function actionScan(): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Scanning content for asset reference tags…\n");

        $lastLine = 0;

        $result = Plugin::getInstance()->scanner->scan(500, function(int $scanned, int $total) use (&$lastLine) {
            if (!$this->progress) {
                return;
            }

            // Only redraw when the number actually changed, so piping to a file does not produce
            // thousands of identical lines.
            if ($scanned !== $lastLine) {
                $lastLine = $scanned;
                Console::updateProgress($scanned, max($total, $scanned));
            }
        });

        if ($this->progress) {
            Console::endProgress();
        }

        $this->stdout(sprintf(
            "Read %d content rows. Found %d references to %d assets across %d places.\n",
            $result['scanned'],
            $result['rows'],
            $result['assets'],
            $result['elements'],
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** List assets nothing in the site points at. */
    public function actionUnused(): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $plugin = Plugin::getInstance();
        $stats = $plugin->usage->getStats();

        // Worded as "none are recorded" rather than "no scan has run": a scan that found nothing
        // leaves the same zero behind as never having scanned, and claiming the latter is wrong
        // half the time.
        if ($stats['refRows'] === 0) {
            $this->stdout("No typed reference tags are recorded. If you have not run `craft library/usage/scan`,\n", Console::FG_YELLOW);
            $this->stdout("run it first — relations are read live, but typed references are only found by a scan.\n\n", Console::FG_YELLOW);
        }

        $this->stdout(sprintf("%d of %d assets are not referenced by any content.\n\n", $stats['unused'], $stats['total']));

        $ids = $plugin->usage->getUnusedAssetIds($this->limit);

        if (!$ids) {
            return ExitCode::OK;
        }

        $rows = [];

        foreach (\craft\elements\Asset::find()->id($ids)->status(null)->all() as $asset) {
            $rows[] = [
                (string)$asset->id,
                (string)$asset->getFilename(),
                (string)($asset->getVolume()->name ?? ''),
                $asset->size ? Craft::$app->getFormatter()->asShortSize($asset->size) : '',
            ];
        }

        $this->table(['ID', 'File', 'Volume', 'Size'], $rows);

        if ($stats['unused'] > count($rows)) {
            $this->stdout(sprintf("\n…and %d more.\n", $stats['unused'] - count($rows)));
        }

        return ExitCode::OK;
    }

    private function requirePro(): bool
    {
        if (Plugin::getInstance()->isPro()) {
            return true;
        }

        $this->stderr("Usage tracking is part of Library Pro.\n", Console::FG_RED);

        return false;
    }
}

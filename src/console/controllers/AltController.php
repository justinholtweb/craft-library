<?php

declare(strict_types=1);

namespace justinholtweb\library\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\library\models\Settings;
use justinholtweb\library\Plugin;
use yii\console\ExitCode;

/**
 * Alternative text.
 */
class AltController extends Controller
{
    /** Where the text comes from: `filename` or `title`. */
    public string $source = Settings::ALT_SOURCE_FILENAME;

    /** Replace alt text that is already there. Off by default, and worth keeping that way. */
    public bool $overwrite = false;

    /** Report what would change without saving anything. */
    public bool $dryRun = false;

    /** Site handle to work on. Alt text is per site. */
    public ?string $site = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'fill' => ['source', 'overwrite', 'dryRun', 'site'],
            'report' => ['site'],
            default => [],
        });
    }

    /** Report how many images have no alternative text. */
    public function actionReport(): int
    {
        $siteId = $this->resolveSiteId();

        if ($siteId === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $stats = Plugin::getInstance()->alt->getStats($siteId);

        $this->stdout(sprintf(
            "%d images, %d described, %d missing alt text.\n",
            $stats['images'],
            $stats['described'],
            $stats['missing'],
        ), $stats['missing'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Fill in missing alternative text from the filename or the title.
     *
     * What this writes is a first draft. It is better than an empty attribute for anybody using a
     * screen reader, and it gives an editor something to correct rather than a blank field to find.
     */
    public function actionFill(): int
    {
        if (!in_array($this->source, [Settings::ALT_SOURCE_FILENAME, Settings::ALT_SOURCE_TITLE], true)) {
            $this->stderr("--source must be `filename` or `title`.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $siteId = $this->resolveSiteId();

        if ($siteId === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $plugin = Plugin::getInstance();
        $alt = $plugin->alt;

        if ($this->dryRun) {
            $assets = $alt->missingAltQuery($siteId)->limit(50)->all();
            $rows = [];

            foreach ($assets as $asset) {
                $rows[] = [(string)$asset->id, (string)$asset->getFilename(), (string)$alt->deriveAlt($asset, $this->source)];
            }

            if (!$rows) {
                $this->stdout("Nothing to do.\n", Console::FG_GREEN);
                return ExitCode::OK;
            }

            $this->table(['ID', 'File', 'Would become'], $rows);

            $total = $alt->getMissingAltCount($siteId);
            $this->stdout(sprintf("\nDry run: %d images would be given alt text. Nothing was saved.\n", $total), Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $total = $this->overwrite
            ? null
            : $alt->getMissingAltCount($siteId);

        if ($total === 0) {
            $this->stdout("Every image on this site already has alt text.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $changed = 0;
        $offset = 0;
        $batch = 100;

        // Paged by re-querying rather than by offset: every save takes an image out of the
        // "missing alt" set, so an offset would step over the ones that shuffled down into it.
        while (true) {
            $assets = $this->overwrite
                // Overwriting does not shrink the set, so this one pages by offset — and honours the
                // excluded volumes exactly as the audit does.
                ? $alt->imagesQuery($siteId)
                    ->orderBy(['elements.id' => SORT_ASC])
                    ->offset($offset)
                    ->limit($batch)
                    ->all()
                : $alt->missingAltQuery($siteId)->limit($batch)->all();

            if (!$assets) {
                break;
            }

            $done = $alt->fill($assets, $this->source, $this->overwrite, $siteId);
            $changed += $done;
            $offset += count($assets);

            if ($total !== null) {
                Console::updateProgress(min($changed, $total), $total);
            }

            // Nothing changed and nothing to skip past: without this a batch that cannot be saved
            // would be re-read forever.
            if ($done === 0 && !$this->overwrite) {
                break;
            }
        }

        if ($total !== null) {
            Console::endProgress();
        }

        $this->stdout("Alt text set on $changed images. Worth a read before you rely on it.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function resolveSiteId(): ?int
    {
        $sites = Craft::$app->getSites();

        if ($this->site === null) {
            return $sites->getPrimarySite()->id;
        }

        $site = $sites->getSiteByHandle($this->site);

        if ($site === null) {
            $this->stderr("No site with the handle `{$this->site}`.\n", Console::FG_RED);
            return null;
        }

        return $site->id;
    }
}

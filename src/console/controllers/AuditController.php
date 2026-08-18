<?php

declare(strict_types=1);

namespace justinholtweb\library\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\library\Plugin;
use yii\console\ExitCode;

/**
 * Duplicate detection and library housekeeping.
 */
class AuditController extends Controller
{
    /** Keep hashing until there is nothing left, rather than one batch. */
    public bool $all = true;

    /** How many duplicate groups to list. */
    public int $limit = 25;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'hash' => ['all'],
            'duplicates' => ['limit'],
            default => [],
        });
    }

    /**
     * Hash files that share a size with another file, so duplicates can be found.
     *
     * Nothing is read unless its size has a twin, and files over the configured size cap are left
     * out. This is the command to run from cron on a large library rather than clicking through the
     * control panel.
     */
    public function actionHash(): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $duplicates = Plugin::getInstance()->duplicates;
        $pending = $duplicates->getPendingHashCount();

        if ($pending === 0) {
            $this->stdout("Nothing to hash.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stdout("About $pending files to hash.\n");

        $total = 0;

        while (true) {
            $done = $duplicates->hashBatch();
            $total += $done;

            Console::updateProgress($total, max($pending, $total));

            // Stops on a batch that hashed nothing, not on a pending count of zero: a file that
            // cannot be read is recorded with a null hash so it is not retried forever, which means
            // pending can legitimately stall above zero.
            if ($done === 0 || !$this->all) {
                break;
            }
        }

        Console::endProgress();

        $remaining = $duplicates->getPendingHashCount();

        $this->stdout("Hashed $total files.\n", Console::FG_GREEN);

        if ($remaining > 0) {
            $this->stdout("$remaining could not be hashed — check the logs, and the maxHashFileSizeMb setting.\n", Console::FG_YELLOW);
        }

        $copies = $duplicates->getDuplicateCount();

        $this->stdout($copies > 0
            ? "$copies duplicate copies found.\n"
            : "No duplicates found.\n");

        return ExitCode::OK;
    }

    /** List duplicate groups. */
    public function actionDuplicates(): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $plugin = Plugin::getInstance();
        $pending = $plugin->duplicates->getPendingHashCount();

        if ($pending > 0) {
            $this->stdout("$pending files are still unhashed, so this list is incomplete. Run `craft library/audit/hash`.\n\n", Console::FG_YELLOW);
        }

        $groups = $plugin->duplicates->getDuplicateGroups($this->limit);

        if (!$groups) {
            $this->stdout("No duplicates found.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        foreach ($groups as $group) {
            $formatter = Craft::$app->getFormatter();
            $wasted = $group['size'] * (count($group['assets']) - 1);
            $this->stdout(sprintf(
                "\n%d identical files, %s each, wasting %s:\n",
                count($group['assets']),
                $formatter->asShortSize($group['size']),
                $formatter->asShortSize($wasted),
            ), Console::FG_CYAN);

            foreach ($group['assets'] as $i => $asset) {
                $this->stdout(sprintf(
                    "  %s #%d  %s  (%s)\n",
                    $i === 0 ? 'keep  ' : 'copy  ',
                    $asset->id,
                    $asset->getFilename(),
                    $asset->getVolume()->name ?? '',
                ));
            }
        }

        $this->stdout("\nMerge copies from the Audit screen so relations are repointed before anything is trashed.\n");

        return ExitCode::OK;
    }

    /** Delete membership rows whose asset has gone. */
    public function actionPrune(): int
    {
        $pruned = Plugin::getInstance()->membership->pruneOrphans();

        $this->stdout("Pruned $pruned orphaned collection rows.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function requirePro(): bool
    {
        if (Plugin::getInstance()->isPro()) {
            return true;
        }

        $this->stderr("Duplicate detection is part of Library Pro.\n", Console::FG_RED);

        return false;
    }
}

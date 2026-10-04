<?php

declare(strict_types=1);

namespace justinholtweb\library\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Asset;
use craft\elements\User;
use craft\queue\BaseBatchedJob;
use justinholtweb\library\models\Settings;
use justinholtweb\library\models\UploadRule;
use justinholtweb\library\Plugin;

/**
 * Applies one upload rule to the assets already in the library, for a rule added after the fact.
 *
 * A queue job because a rule that matches every file in a 50,000-asset library is not a web
 * request, and a capped request can only ever see the same first slice. Batched in ID order, so a
 * retry or a second run picks up where the first stopped rather than starting over.
 *
 * It runs as nobody, so it carries the ID of the user who asked for it and checks save permission
 * against them per asset — the same rule every bulk action follows with `savable()`.
 */
class BackfillRule extends BaseBatchedJob
{
    public int $ruleId;
    public ?int $userId = null;

    public int $matched = 0;
    public int $assigned = 0;

    private ?UploadRule $rule = null;
    private ?User $user = null;
    private bool $resolved = false;

    protected function loadData(): Batchable
    {
        $rule = $this->rule();
        $query = Asset::find()->status(null)->orderBy(['elements.id' => SORT_ASC]);

        if ($rule === null) {
            // Deleted while queued: nothing to do, and an empty batcher says so.
            $query->id(false);
        } else {
            if ($rule->volumeId !== null) {
                $query->volumeId($rule->volumeId);
            }

            if ($rule->kinds) {
                $query->kind($rule->kinds);
            }
        }

        return new QueryBatcher($query);
    }

    protected function processItem(mixed $item): void
    {
        $rule = $this->rule();

        if (!$item instanceof Asset || $rule === null || !$rule->matches($item)) {
            return;
        }

        if ($this->userId !== null && !Craft::$app->getElements()->canSave($item, $this->user())) {
            return;
        }

        $this->matched++;
        $plugin = Plugin::getInstance();

        if ($rule->collectionIds) {
            $this->assigned += $plugin->membership->assign([(int)$item->id], $rule->collectionIds);
        }

        // Same promise as on upload: only ever fills an empty alt, never replaces one.
        if (
            $rule->altSource !== null &&
            $rule->altSource !== Settings::ALT_SOURCE_NONE &&
            $item->kind === Asset::KIND_IMAGE &&
            trim((string)$item->alt) === ''
        ) {
            $plugin->alt->fill([$item], $rule->altSource, false, (int)$item->siteId);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('library', 'Applying an upload rule to existing assets');
    }

    private function rule(): ?UploadRule
    {
        return $this->rule ??= Plugin::getInstance()->rules->getRuleById($this->ruleId);
    }

    private function user(): ?User
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $this->user = $this->userId !== null ? User::find()->id($this->userId)->status(null)->one() : null;
        }

        return $this->user;
    }
}

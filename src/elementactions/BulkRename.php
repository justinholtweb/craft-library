<?php

declare(strict_types=1);

namespace justinholtweb\library\elementactions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\library\Plugin;
use justinholtweb\library\web\assets\cp\CpAsset;

/**
 * “Rename files” — pattern-based bulk renaming.
 *
 * Previews by default. This is the only action in the plugin that rewrites bytes on the filesystem,
 * and a pattern that looked right in your head but produces 400 files called `image.jpg` is not
 * something you want to discover afterwards. The editor has to deliberately turn the preview off.
 */
class BulkRename extends ElementAction
{
    public string $pattern = '{basename}.{ext}';

    /** On by default. Turning it off is the "yes, really" step. */
    public bool $preview = true;

    public function getTriggerLabel(): string
    {
        return Craft::t('library', 'Rename files');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['pattern'], 'required'];
        $rules[] = [['pattern'], 'string', 'max' => 255];
        $rules[] = [['preview'], 'boolean'];
        return $rules;
    }

    /**
     * A clean HTML id. The default is the class name, backslashes and all, which is not a valid id
     * and has to be escaped at every jQuery selector that looks for it.
     */
    public function getTriggerId(): string
    {
        return 'library-bulk-rename';
    }

    public function getTriggerHtml(): ?string
    {
        // The picker styles and Craft.LibraryActionTrigger both live in this bundle, and nothing
        // else pulls it onto the asset index.
        Craft::$app->getView()->registerAssetBundle(CpAsset::class);

        return Craft::$app->getView()->renderTemplate('library/_actions/rename', [
            'type' => static::class,
            'triggerId' => $this->getTriggerId(),
            'label' => $this->getTriggerLabel(),
            'tokens' => Plugin::getInstance()->renamer::tokens(),
        ]);
    }

    /**
     * Narrows the action to assets this user may actually save.
     *
     * Craft's element index hands an action whatever the reader can *see*, and `viewAssets` is a
     * real permission without `saveAssets` — a read-only volume, or someone else's uploads when
     * `savePeerAssets` is not granted. Without this, one global "bulk edit" permission would let an
     * editor rename, move or re-describe files in volumes they only have read access to.
     */
    private function savableOnly(ElementQueryInterface $query): ElementQueryInterface
    {
        /** @var AssetQuery $query */
        return $query->savable();
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $assets = $this->savableOnly($query)->all();

        if (!$assets) {
            $this->setMessage(Craft::t('library', 'No assets were selected.'));
            return true;
        }

        $renamer = Plugin::getInstance()->renamer;

        if ($this->preview) {
            $rows = $renamer->preview($assets, $this->pattern);
            $changed = array_values(array_filter($rows, fn($row) => $row['changed'] && $row['problem'] === null));
            $problems = array_values(array_filter($rows, fn($row) => $row['problem'] !== null));

            if (!$changed && !$problems) {
                $this->setMessage(Craft::t('library', 'That pattern would not change any of the selected filenames.'));
                return true;
            }

            // A couple of real examples say more about a pattern than any count does.
            $examples = array_map(
                fn($row) => "{$row['from']} → {$row['to']}",
                array_slice($changed, 0, 3),
            );

            $message = Craft::t('library', 'Preview: {n, plural, =1{1 file} other{# files}} would be renamed.', ['n' => count($changed)]);

            if ($examples) {
                $message .= ' ' . implode(', ', $examples) . (count($changed) > 3 ? '…' : '');
            }

            if ($problems) {
                $message .= ' ' . Craft::t('library', '{n, plural, =1{1 file has a problem} other{# files have problems}}: {first}', [
                    'n' => count($problems),
                    'first' => $problems[0]['problem'],
                ]);
            }

            $message .= ' ' . Craft::t('library', 'Turn off “Preview only” to apply.');

            // Reported as a failure so Craft shows it as a notice the reader has to dismiss, rather
            // than a green tick that reads as "done".
            $this->setMessage($message);

            return false;
        }

        $result = $renamer->rename($assets, $this->pattern);

        if ($result['failed'] > 0 || $result['errors']) {
            $this->setMessage(Craft::t('library', 'Renamed {renamed}, skipped {skipped}, failed {failed}. First problem: {error}', [
                'renamed' => $result['renamed'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'error' => $result['errors'][0] ?? '',
            ]));

            return $result['renamed'] > 0;
        }

        $this->setMessage(Craft::t('library', '{n, plural, =1{1 file} other{# files}} renamed.', ['n' => $result['renamed']]));

        return true;
    }
}

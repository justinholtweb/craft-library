<?php

declare(strict_types=1);

namespace justinholtweb\library\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;
use craft\web\View;

class CpAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CraftCpAsset::class,
    ];

    public $js = ['library-cp.js'];
    public $css = ['library-cp.css'];

    /**
     * `Craft.t()` in the browser only knows the strings registered here — anything else comes back
     * in English whatever the user's language. Covers library-cp.js and the `{% js %}` blocks in the
     * templates, which all load this bundle.
     */
    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerTranslations('library', [
                'Choose at least one collection.',
                'Couldn’t save. Your change was not kept.',
                'Delete “{name}”? The assets in it are not deleted.',
                'Delete this rule? Assets already filed by it stay where they are.',
                'Done. Reloading…',
                'Enter a filename pattern.',
                'Hashed {done} of about {total}…',
                'Hashing stopped because of an error. Check the logs.',
                'Rule deleted.',
                'Saved',
                'Saving…',
                'This renames the files on disk. Continue?',
            ]);
        }
    }
}

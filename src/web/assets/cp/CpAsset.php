<?php

declare(strict_types=1);

namespace justinholtweb\library\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

class CpAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CraftCpAsset::class,
    ];

    public $js = ['library-cp.js'];
    public $css = ['library-cp.css'];
}

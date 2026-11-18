<?php

namespace Wexample\SymfonyApiMcp\Traits;

use Wexample\SymfonyApiMcp\WexampleSymfonyApiMcpBundle;
use Wexample\SymfonyHelpers\Traits\BundleClassTrait;

trait SymfonyApiMcpBundleClassTrait
{
    use BundleClassTrait;

    public static function getBundleClassName(): string
    {
        return WexampleSymfonyApiMcpBundle::class;
    }
}

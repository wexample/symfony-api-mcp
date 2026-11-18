<?php

namespace Wexample\SymfonyApiMcp;

use Wexample\SymfonyHelpers\Class\AbstractBundle;
use Wexample\SymfonyHelpers\Helper\BundleHelper;
use Wexample\SymfonyHelpers\Interface\LoaderBundleInterface;

class WexampleSymfonyApiMcpBundle extends AbstractBundle implements LoaderBundleInterface
{
    /**
     * The MCP server's page (assets/pages/mcp/).
     */
    public static function getLoaderFrontPaths(): array
    {
        return [
            BundleHelper::getBundleCssAlias(static::class) => __DIR__ . '/../assets/',
        ];
    }
}

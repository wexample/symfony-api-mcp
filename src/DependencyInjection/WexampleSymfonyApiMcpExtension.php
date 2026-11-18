<?php

namespace Wexample\SymfonyApiMcp\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

/**
 * One MCP server, `api`, served at `/api/mcp` — where the application's api
 * lives, behind its firewall — exposing every tool the application declares
 * (`#[McpTool]` on a service). The application's own `mcp` configuration
 * comes after this one and wins: its name, its hosts, its tools.
 */
class WexampleSymfonyApiMcpExtension extends Extension implements PrependExtensionInterface
{
    final public const string SERVER = 'api';

    final public const string PATH = '/api/mcp';

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('mcp', [
            'servers' => [
                self::SERVER => [
                    'name' => $container->hasParameter('kernel.project_dir') ? basename((string) $container->getParameter('kernel.project_dir')) : null,
                    'transports' => ['http' => true, 'stdio' => true],
                    'http' => ['path' => self::PATH],
                    'registry' => ['tools' => ['*']],
                ],
            ],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
    }
}

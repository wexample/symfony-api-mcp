<?php

namespace Wexample\SymfonyApiMcp\Tests\Fixtures\App;

use Symfony\AI\McpBundle\McpBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Wexample\SymfonyApiMcp\WexampleSymfonyApiMcpBundle;
use Wexample\SymfonyLoader\WexampleSymfonyLoaderBundle;
use Wexample\SymfonyTranslations\WexampleSymfonyTranslationsBundle;
use Wexample\SymfonyTesting\Tests\Fixtures\AbstractFixtureKernel;

class AppKernel extends AbstractFixtureKernel
{
    protected function getFixtureDir(): string
    {
        return __DIR__;
    }

    protected function getExtraBundles(): iterable
    {
        // The loader and its translations: what the server's page is drawn by.
        return [new McpBundle(), new WexampleSymfonyLoaderBundle(), new WexampleSymfonyTranslationsBundle(), new WexampleSymfonyApiMcpBundle()];
    }

    protected function getConfigFiles(): array
    {
        return [__DIR__ . '/config/config.yaml'];
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__ . '/../../../src/Resources/config/routes.yaml');
        $routes->import(__DIR__ . '/../../../src/Controller/', 'attribute');
    }
}

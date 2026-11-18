<?php

namespace Wexample\SymfonyApiMcp\Service;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use Mcp\Server;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Wexample\SymfonyApi\Interface\ApiDocumentationSectionInterface;
use Wexample\SymfonyApiMcp\DependencyInjection\WexampleSymfonyApiMcpExtension;

/**
 * The api's page says how an agent reaches the MCP server and which tools it
 * finds there — to the readers of the whole documentation, not a preview's.
 */
final readonly class McpDocumentationSection implements ApiDocumentationSectionInterface
{
    public function __construct(
        // Built first: its builder is what fills the registry.
        #[Autowire(service: 'mcp.server.' . WexampleSymfonyApiMcpExtension::SERVER)]
        private Server $server,
        #[Autowire(service: 'mcp.server.' . WexampleSymfonyApiMcpExtension::SERVER . '.registry')]
        private RegistryInterface $registry,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function isShown(bool $preview): bool
    {
        return ! $preview;
    }

    public function getTemplate(): string
    {
        return '@WexampleSymfonyApiMcp/documentation_section.html.twig';
    }

    public function getContext(bool $preview): array
    {
        $url = $this->urlGenerator->generate('_mcp_endpoint_' . WexampleSymfonyApiMcpExtension::SERVER, [], UrlGeneratorInterface::ABSOLUTE_URL);
        $tools = array_values($this->registry->getTools()->references);
        usort($tools, static fn (Tool $a, Tool $b) => $a->name <=> $b->name);

        return [
            'url' => $url,
            'command' => sprintf("claude mcp add --transport http %s %s", basename(parse_url($url, PHP_URL_HOST) ?: 'api'), $url),
            'tools' => array_map(static fn (Tool $tool) => [
                'name' => $tool->name,
                'title' => $tool->annotations?->title ?? $tool->title,
                'description' => $tool->description,
                'readOnly' => (bool) $tool->annotations?->readOnlyHint,
                'arguments' => array_map(
                    static fn (string $name, array $schema) => [
                        'name' => $name,
                        'type' => is_array($schema['type'] ?? null) ? implode('|', $schema['type']) : ($schema['type'] ?? 'mixed'),
                        'required' => in_array($name, $tool->inputSchema['required'] ?? [], true),
                        'description' => $schema['description'] ?? null,
                    ],
                    array_keys($tool->inputSchema['properties'] ?? []),
                    array_values($tool->inputSchema['properties'] ?? [])
                ),
            ], $tools),
        ];
    }
}

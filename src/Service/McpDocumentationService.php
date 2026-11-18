<?php

namespace Wexample\SymfonyApiMcp\Service;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use Mcp\Server;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Wexample\SymfonyApiMcp\DependencyInjection\WexampleSymfonyApiMcpExtension;

/**
 * What the MCP server's page says: where an agent connects, how, and the
 * tools it finds there.
 */
final readonly class McpDocumentationService
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

    /**
     * @return array{url: string, command: string, tools: list<array<string, mixed>>}
     */
    public function describe(): array
    {
        $url = $this->urlGenerator->generate('_mcp_endpoint_' . WexampleSymfonyApiMcpExtension::SERVER, [], UrlGeneratorInterface::ABSOLUTE_URL);
        $tools = array_values($this->registry->getTools()->references);
        usort($tools, static fn (Tool $a, Tool $b) => $a->name <=> $b->name);

        return [
            'url' => $url,
            // Signed as any api call: an account's token, in the header.
            'command' => sprintf(
                "claude mcp add --transport http %s \\\n  %s \\\n  --header 'Authorization: Bearer \$TOKEN'",
                explode('.', parse_url($url, PHP_URL_HOST) ?: 'api')[0],
                $url
            ),
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

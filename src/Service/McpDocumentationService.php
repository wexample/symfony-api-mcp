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
            'tools' => array_map(fn (Tool $tool) => $this->describeTool($tool), $tools),
        ];
    }

    /**
     * A tool as its page reads it: its names, what it does, its arguments
     * typed as an agent writes them, and a call of it, ready to be copied.
     *
     * @return array<string, mixed>
     */
    private function describeTool(Tool $tool): array
    {
        // A tool taking nothing has its properties as an empty object.
        $properties = (array) ($tool->inputSchema['properties'] ?? []);
        $required = $tool->inputSchema['required'] ?? [];
        $arguments = [];
        $example = [];

        foreach ($properties as $name => $schema) {
            $schema = self::toArray($schema);
            $arguments[] = [
                'name' => $name,
                'type' => self::typeOf($schema),
                'required' => in_array($name, $required, true),
                'description' => $schema['description'] ?? null,
            ];
            $example[$name] = self::exampleOf($schema);
        }

        return [
            'name' => $tool->name,
            'title' => $tool->annotations?->title ?? $tool->title,
            'description' => $tool->description,
            'readOnly' => (bool) $tool->annotations?->readOnlyHint,
            'arguments' => $arguments,
            // The request an agent sends for it, as the protocol has it.
            'example' => json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool->name, 'arguments' => (object) $example],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * What a value of the schema is, as a type is written: `string`,
     * `string|null`, `array<string>`, `array|object` for what may be either,
     * its values listed when they are few (`"pass"|"fail"`), a referenced
     * schema by its name; `mixed` when it says nothing.
     */
    private static function typeOf(array $schema): string
    {
        $types = [];

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $types = array_map(static fn (mixed $value) => null === $value ? 'null' : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $schema['enum']);
        } elseif (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $types[] = substr($schema['$ref'], (int) strrpos($schema['$ref'], '/') + 1);
        } elseif (isset($schema['oneOf']) || isset($schema['anyOf'])) {
            foreach ($schema['oneOf'] ?? $schema['anyOf'] as $alternative) {
                $types = array_merge($types, explode('|', self::typeOf(self::toArray($alternative))));
            }
        } else {
            foreach ((array) ($schema['type'] ?? []) as $type) {
                $items = 'array' === $type ? self::toArray($schema['items'] ?? []) : [];
                $itemType = [] === $items ? 'mixed' : self::typeOf($items);
                $types[] = 'array' === $type && ! in_array($itemType, ['mixed', 'mixed|null'], true)
                    ? 'array<'.$itemType.'>'
                    : (string) $type;
            }
        }

        if (true === ($schema['nullable'] ?? false)) {
            $types[] = 'null';
        }

        $types = array_values(array_unique($types));

        return [] === $types || ['null'] === $types ? 'mixed' : implode('|', $types);
    }

    /**
     * A value standing for one of the schema, in a call shown as an example:
     * its default, its first allowed value, or the empty one of its type.
     */
    private static function exampleOf(array $schema): mixed
    {
        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && [] !== $schema['enum']) {
            return $schema['enum'][0];
        }

        foreach (['oneOf', 'anyOf'] as $key) {
            if (isset($schema[$key][0])) {
                return self::exampleOf(self::toArray($schema[$key][0]));
            }
        }

        $type = array_values(array_diff((array) ($schema['type'] ?? []), ['null']))[0] ?? null;

        return match ($type) {
            'string' => '…',
            'integer', 'number' => 0,
            'boolean' => true,
            'array' => [],
            'object' => new \stdClass(),
            default => null,
        };
    }

    private static function toArray(mixed $schema): array
    {
        return is_array($schema) ? $schema : (array) $schema;
    }
}

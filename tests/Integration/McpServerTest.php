<?php

namespace Wexample\SymfonyApiMcp\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The application's tools at /api/mcp, as an MCP client reaches them.
 */
class McpServerTest extends WebTestCase
{
    private KernelBrowser $client;

    private ?string $session = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAToolDeclaredOnAServiceIsListedAndCalled(): void
    {
        $initialize = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]);
        $this->assertArrayHasKey('tools', $initialize['result']['capabilities']);
        $this->rpc('notifications/initialized', notification: true);

        $tools = array_column($this->rpc('tools/list')['result']['tools'], null, 'name');
        $this->assertSame('Greets someone by name.', $tools['greet']['description']);
        $this->assertSame(['name'], $tools['greet']['inputSchema']['required']);

        $call = $this->rpc('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'Ada']]);
        $this->assertSame('Hello Ada.', $call['result']['content'][0]['text']);
    }

    public function testTheApiPageSaysWhereAndWhichTools(): void
    {
        $section = self::getContainer()->get('test.mcp_documentation_section');

        $this->assertFalse($section->isShown(preview: true));
        $context = $section->getContext(preview: false);
        $this->assertStringEndsWith('/api/mcp', $context['url']);
        $this->assertStringContainsString('claude mcp add --transport http', $context['command']);
        $this->assertSame([['name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Who to greet']], $context['tools'][0]['arguments']);
    }

    private function rpc(string $method, array $params = [], bool $notification = false): ?array
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method] + ([] === $params ? [] : ['params' => $params]) + ($notification ? [] : ['id' => uniqid()]);
        $this->client->request('POST', '/api/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ] + ($this->session ? ['HTTP_MCP_SESSION_ID' => $this->session] : []), content: json_encode($message));

        $response = $this->client->getResponse();
        $this->session ??= $response->headers->get('Mcp-Session-Id');
        $this->assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());

        return $notification ? null : json_decode((string) $response->getContent(), true);
    }
}

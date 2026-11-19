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

    /**
     * An api action marked #[McpToolRoute]: a tool named after its route,
     * taking its path variables, calling the action — its answer given
     * back, an error status said as the tool's error. An action unmarked is
     * no tool.
     */
    public function testAnApiActionMarkedIsATool(): void
    {
        $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]);
        $this->rpc('notifications/initialized', notification: true);

        $tools = array_column($this->rpc('tools/list')['result']['tools'], null, 'name');
        $this->assertArrayNotHasKey('api_unlisted', $tools);
        $this->assertSame('Says a word back, as many times as asked.', $tools['api_echo']['description']);
        $this->assertSame(['word'], $tools['api_echo']['inputSchema']['required']);

        $call = $this->rpc('tools/call', ['name' => 'api_echo', 'arguments' => ['word' => 'hey', 'times' => 2]]);
        $this->assertSame(['said' => 'hey hey'], $call['result']['structuredContent'] ?? json_decode($call['result']['content'][0]['text'], true));

        // A GET given what its document does not name: sent in the query, lists included.
        $read = $this->rpc('tools/call', ['name' => 'api_unlisted_tool', 'arguments' => ['tags' => ['a', 'b'], 'limit' => 3]]);
        $this->assertSame(['tags' => ['a', 'b'], 'limit' => '3'], ($read['result']['structuredContent'] ?? json_decode($read['result']['content'][0]['text'], true))['query']);

        $failed = $this->rpc('tools/call', ['name' => 'api_echo', 'arguments' => ['word' => 'hey', 'times' => 0]]);
        $this->assertTrue($failed['result']['isError']);
        $this->assertStringStartsWith('400:', $failed['result']['content'][0]['text']);
    }

    public function testThePageSaysWhereHowAndWhichTools(): void
    {
        $documentation = self::getContainer()->get('test.mcp_documentation');

        $server = $documentation->describe();
        $this->assertStringEndsWith('/api/mcp', $server['url']);
        $this->assertStringContainsString('claude mcp add --transport http', $server['command']);
        $this->assertStringContainsString("--header 'Authorization: Bearer \$TOKEN'", $server['command']);
        $tools = array_column($server['tools'], null, 'name');
        $this->assertSame([['name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Who to greet']], $tools['greet']['arguments']);
        // A tool taking nothing: none listed.
        $this->assertSame([], $tools['api_unlisted_tool']['arguments']);
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

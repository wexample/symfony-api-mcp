## Turning it on

```php
// config/bundles.php
Symfony\AI\McpBundle\McpBundle::class => ['all' => true],
Wexample\SymfonyApiMcp\WexampleSymfonyApiMcpBundle::class => ['all' => true],
```

```yaml
# config/routes/mcp.yaml
mcp:
    resource: '@WexampleSymfonyApiMcpBundle/Resources/config/routes.yaml'
```

The package builds on `symfony/mcp-bundle` (and the official `mcp/sdk`), giving it one server, `api`:

- served over HTTP at **`/api/mcp`** — the api's prefix, so the api's firewall and access rules apply (`usage/route-prefixes` of symfony-api) — and over STDIO by `bin/console mcp:server`;
- named after the application's directory;
- exposing **every tool** the application declares.

Anything else is the application's `mcp` configuration, which comes after the package's and wins:

```yaml
mcp:
    servers:
        api:
            name: speech
            description: The speeches of French political debates.
            http:
                # The SDK answers localhost only, against DNS rebinding: a
                # public server lists its hosts.
                allowed_hosts: ['speech.example.org']
```

## A tool

A method of a service, its arguments typed and documented — the schema the agent reads is derived from them:

```php
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

final readonly class PeopleTools
{
    /**
     * People whose name contains the query.
     *
     * @param string $query Part of a name
     * @param int    $limit At most this many
     */
    #[McpTool(name: 'search_people', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function search(string $query, int $limit = 20): array { /* … */ }
}
```

- The returned value is what the agent reads: a string, or an array sent as JSON.
- A tool sees the account its request was signed in as (`Security`): the api's tokens, the voters, as any api route.
- Few tools named for what an agent wants to do read better than one per route: an agent picks among their descriptions.

`bin/console debug:mcp` lists what a server exposes.

## On the api's page

symfony-api's page, `/api/`, ends on the MCP server for whoever reads the whole documentation (not a preview): its address, the command adding it to an agent (`claude mcp add --transport http …`), and each tool — its title, description, arguments, read-only or not.

## Signed in

`/api/mcp` is behind the api's firewall: an agent sends the account's token (`Authorization: Bearer …`, symfony-api's user tokens), and the access rules of `^/api/` apply — an api kept to the administrators keeps its MCP server to them.

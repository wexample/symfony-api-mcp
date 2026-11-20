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

## An api operation as a tool

An action of the api already has its route, its security, its validation and its documentation: `#[McpToolRoute]` serves it as a tool too, nothing written twice.

```php
use Wexample\SymfonyApiMcp\Attribute\McpToolRoute;

#[Route(path: 'session/create', name: 'v20261_session_create', methods: ['POST'])]
#[McpToolRoute(name: 'session_create', description: 'Opens a session with a scenario.')]
public function create(CreateSessionDto $dto /* … */): ApiResponse { /* … */ }
```

- **Its name**: the attribute's, else the route's.
- **What it takes**, read from the api's OpenAPI document (symfony-api, NelmioApiDocBundle): the route's path variables, its query parameters, its JSON body's fields — typed, described and required as documented; a path variable the route gives a default to stays optional. Undocumented, the path variables alone, and a body taken as given.
- **Its description**: the attribute's, else the operation's summary and description.
- **Called**, the tool calls the action itself in a sub-request of the MCP one: the account of the token that signed the MCP request (its header, and symfony-api's request attribute holding it), the same checks, the same answer — its JSON given back; an error status is the tool's error (`isError`, the status and the api's answer as its text). The MCP request is marked with the tool it called, its name in the request attribute `_mcp_tool` (`ApiRouteToolLoader::REQUEST_ATTRIBUTE_TOOL`), for what counts the calls afterwards — symfony-analytics per tool — without depending on this package.
- A `GET` is marked read-only. A method without a body sends in its query what the document does not place, rather than losing it; every query value is sent as a URL carries it — strings, lists as `key[]` —, what the action reads from a real request.
- **The tool is as right as the document**: the MCP server checks the arguments against the schema before calling anything, so an operation documented wrongly refuses what it would accept. Fix the document (symfony-api's describers), and Swagger UI and the tool are right together.
- **Not carried**: files — an upload is multipart, an MCP call is JSON; and a body field named as a path or query parameter, which the parameter takes.

Few tools named for what an agent wants to do still read better than one per route: mark the operations an agent has a use for, not every one.

## Its page

The MCP server has its own page, `/api/doc/mcp`, beside symfony-api's `/api/`, which the application's menu leads to: its address, the command adding it to an agent (`claude mcp add --transport http …`, the token in its header), and each tool — its title, description, arguments, read-only or not. It is read by whoever reads the whole of the api's documentation (`documentation.access_role`).

An application loading its bundles' controllers (`routing.controllers`) has the page already; one naming them imports `@WexampleSymfonyApiMcpBundle/Controller/` (type `attribute`). Like Swagger UI, the page sits under `/api/doc`, which the application keeps off its token firewall: `^/api/(?!$|doc)`.

## Signed in

`/api/mcp` is behind the api's firewall: an agent sends the account's token (`Authorization: Bearer …`, symfony-api's user tokens), and the access rules of `^/api/` apply — an api kept to the administrators keeps its MCP server to them.

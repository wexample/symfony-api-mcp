<?php

namespace Wexample\SymfonyApiMcp\Service;

use Closure;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Wexample\SymfonyApi\Security\AbstractApiTokenHandler;
use Wexample\SymfonyApi\Service\ApiDocumentationService;
use Wexample\SymfonyApiMcp\Attribute\McpToolRoute;

/**
 * Every action of the api marked `#[McpToolRoute]` served as an MCP tool.
 *
 * What the tool takes: the route's path variables, and as the api's OpenAPI
 * document says the operation — its query parameters, its JSON body's
 * fields, each typed and described as documented. Called, the tool calls
 * the action itself, in a sub-request of the MCP one: the same account
 * (the token that signed the MCP request — its header, and the api's
 * request attribute holding it), the same checks, the same
 * answer — its JSON given back, an error status said as the tool's error.
 */
final readonly class ApiRouteToolLoader implements LoaderInterface
{
    // The headers the action is called with, from the MCP request.
    private const array FORWARDED_HEADERS = ['Authorization', 'Accept-Language'];

    private const array BODILESS_METHODS = ['GET', 'HEAD', 'DELETE'];

    public function __construct(
        private RouterInterface $router,
        private HttpKernelInterface $kernel,
        private RequestStack $requestStack,
        // None where the api is not documented: the path variables alone, a body as given.
        private ?ApiDocumentationService $documentation = null,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        $document = null;

        foreach ($this->router->getRouteCollection() as $name => $route) {
            $attribute = $this->attributeOf($route);
            if (null === $attribute) {
                continue;
            }

            $document ??= $this->documentation?->getDocument() ?? [];
            $method = strtoupper($route->getMethods()[0] ?? 'GET');
            $operation = $document['paths'][$route->getPath()][strtolower($method)] ?? [];
            [$schema, $query] = $this->inputOf($route, $operation, $document, $method);

            $registry->registerTool(
                new Tool(
                    name: $attribute->name ?? $name,
                    title: $operation['summary'] ?? null,
                    inputSchema: $schema,
                    description: $attribute->description ?? $this->describe($operation, $method, $route),
                    annotations: new ToolAnnotations(readOnlyHint: 'GET' === $method),
                ),
                $this->handler($name, $route, $method, $query),
            );
        }
    }

    /** The attribute on the route's action, when it has one. */
    private function attributeOf(Route $route): ?McpToolRoute
    {
        $controller = $route->getDefault('_controller');
        if (! is_string($controller) || ! str_contains($controller, '::')) {
            return null;
        }

        [$class, $action] = explode('::', $controller, 2);
        if (! method_exists($class, $action)) {
            return null;
        }

        $attributes = (new ReflectionMethod($class, $action))->getAttributes(McpToolRoute::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }

    /**
     * The tool's input schema — path variables, query parameters, body
     * fields side by side —, and the names that go in the query.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function inputOf(Route $route, array $operation, array $document, string $method): array
    {
        $properties = [];
        $required = [];
        $query = [];

        foreach ($route->compile()->getPathVariables() as $variable) {
            $properties[$variable] = ['type' => 'string'];
            if (! $route->hasDefault($variable)) {
                $required[] = $variable;
            }
        }

        foreach ($operation['parameters'] ?? [] as $parameter) {
            $parameter = $this->resolve($parameter, $document);
            $name = $parameter['name'] ?? null;
            if (null === $name || ! in_array($parameter['in'] ?? null, ['path', 'query'], true)) {
                continue;
            }

            $properties[$name] = array_filter([
                ...$this->resolve($parameter['schema'] ?? ['type' => 'string'], $document),
                'description' => $parameter['description'] ?? null,
            ], static fn (mixed $value): bool => null !== $value);
            if ('query' === $parameter['in']) {
                $query[] = $name;
            }
            // A path variable the route gives a default to is optional,
            // whatever the document says of every path parameter.
            $optional = 'path' === $parameter['in'] && $route->hasDefault($name);
            if (($parameter['required'] ?? false) && ! $optional && ! in_array($name, $required, true)) {
                $required[] = $name;
            }
        }

        if (! in_array($method, self::BODILESS_METHODS, true)) {
            $body = $this->resolve($operation['requestBody']['content']['application/json']['schema'] ?? [], $document);
            foreach ($body['properties'] ?? [] as $name => $field) {
                $properties[$name] ??= $this->resolve($field, $document);
            }
            foreach ($body['required'] ?? [] as $name) {
                if (! in_array($name, $required, true)) {
                    $required[] = $name;
                }
            }
        }

        return [
            ['type' => 'object', 'properties' => $properties] + ([] === $required ? [] : ['required' => $required]),
            $query,
        ];
    }

    /**
     * A schema with its references read where they point, down to a depth —
     * a model naming itself stops there —, said as JSON Schema says it.
     */
    private function resolve(array $schema, array $document, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $target = $document;
            foreach (explode('/', ltrim(substr($schema['$ref'], 1), '/')) as $segment) {
                $target = $target[str_replace(['~1', '~0'], ['/', '~'], $segment)] ?? [];
            }
            unset($schema['$ref']);
            $schema = [...(is_array($target) ? $target : []), ...$schema];
        }

        foreach (['properties', 'patternProperties'] as $key) {
            foreach ($schema[$key] ?? [] as $name => $child) {
                $schema[$key][$name] = is_array($child) ? $this->resolve($child, $document, $depth + 1) : $child;
            }
        }
        foreach (['items', 'additionalProperties'] as $key) {
            if (is_array($schema[$key] ?? null)) {
                $schema[$key] = $this->resolve($schema[$key], $document, $depth + 1);
            }
        }
        foreach (['oneOf', 'anyOf', 'allOf'] as $key) {
            foreach ($schema[$key] ?? [] as $index => $child) {
                $schema[$key][$index] = is_array($child) ? $this->resolve($child, $document, $depth + 1) : $child;
            }
        }

        // OpenAPI 3.0's `nullable`, said as JSON Schema says it: null among the types.
        if (array_key_exists('nullable', $schema)) {
            if (true === $schema['nullable'] && isset($schema['type']) && is_string($schema['type'])) {
                $schema['type'] = [$schema['type'], 'null'];
            }
            unset($schema['nullable']);
        }

        return $schema;
    }

    private function describe(array $operation, string $method, Route $route): string
    {
        $said = trim(implode("\n\n", array_filter([$operation['summary'] ?? null, $operation['description'] ?? null])));

        return '' !== $said ? $said : $method.' '.$route->getPath();
    }

    /** What the tool runs: the action, called as the api would be. */
    private function handler(string $name, Route $route, string $method, array $query): Closure
    {
        $pathVariables = $route->compile()->getPathVariables();
        $router = $this->router;
        $kernel = $this->kernel;
        $requestStack = $this->requestStack;
        // Read here: in the reference handler's scope, `self` is not this class.
        $forwarded = self::FORWARDED_HEADERS;
        $bodiless = self::BODILESS_METHODS;

        // Bound to the reference handler's scope: handed the arguments as given.
        return Closure::bind(static function (array $arguments) use ($name, $method, $query, $pathVariables, $router, $kernel, $requestStack, $forwarded, $bodiless): mixed {
            unset($arguments['_session'], $arguments['_request']);

            $path = array_intersect_key($arguments, array_flip($pathVariables));
            $parameters = array_intersect_key($arguments, array_flip($query));
            $body = array_diff_key($arguments, $path, $parameters);

            // A method without a body: what the document does not place goes
            // in the query, not lost.
            if (in_array($method, $bodiless, true)) {
                $parameters += $body;
                $body = [];
            }

            // As a URL carries them: strings, lists as `key[]` — what the
            // action reads from a real request.
            parse_str(http_build_query($parameters), $parameters);

            $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
            $main = $requestStack->getMainRequest();
            foreach ($forwarded as $header) {
                if ($main?->headers->has($header)) {
                    $server['HTTP_'.strtoupper(str_replace('-', '_', $header))] = $main->headers->get($header);
                }
            }

            $request = Request::create(
                $router->generate($name, $path),
                $method,
                $parameters,
                server: $server,
                content: in_array($method, $bodiless, true) || [] === $body ? null : json_encode($body, JSON_THROW_ON_ERROR),
            );
            if ($main?->hasSession()) {
                $request->setSession($main->getSession());
            }
            // The token the MCP request was signed with, as the api's handler
            // left it: what the action reads its account from.
            if ($main?->attributes->has(AbstractApiTokenHandler::REQUEST_ATTRIBUTE_TOKEN)) {
                $request->attributes->set(AbstractApiTokenHandler::REQUEST_ATTRIBUTE_TOKEN, $main->attributes->get(AbstractApiTokenHandler::REQUEST_ATTRIBUTE_TOKEN));
            }

            $response = $kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
            $content = (string) $response->getContent();
            $decoded = json_decode($content, true);

            if ($response->getStatusCode() >= 400) {
                throw new ToolCallException(sprintf('%d: %s', $response->getStatusCode(), is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $content));
            }

            return null === $decoded && '' !== $content ? $content : $decoded;
        }, null, ReferenceHandler::class);
    }
}

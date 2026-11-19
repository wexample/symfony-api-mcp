<?php

namespace Wexample\SymfonyApiMcp\Attribute;

use Attribute;

/**
 * An operation of the api served as an MCP tool as well: put on a
 * controller's action, the action stays what it is — its route, its
 * security, its validation — and a tool calls it (ApiRouteToolLoader). What
 * the tool takes is read from the api's OpenAPI document: the route's path
 * and query parameters, its JSON body's fields.
 *
 * Without a name, the tool is named after its route; without a
 * description, it is described as the api documents the operation.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class McpToolRoute
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $description = null,
    ) {
    }
}

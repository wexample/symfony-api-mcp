<?php

namespace Wexample\SymfonyApiMcp\Tests\Fixtures\App\Tool;

use Mcp\Capability\Attribute\McpTool;

class GreetingTool
{
    /**
     * Greets someone by name.
     *
     * @param string $name Who to greet
     */
    #[McpTool(name: 'greet')]
    public function greet(string $name): string
    {
        return 'Hello ' . $name . '.';
    }
}

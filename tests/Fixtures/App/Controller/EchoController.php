<?php

namespace Wexample\SymfonyApiMcp\Tests\Fixtures\App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyApiMcp\Attribute\McpToolRoute;

/** An api action served as a tool as well: what it was given, said back. */
class EchoController
{
    #[Route('/api/echo/{word}', name: 'api_echo', methods: ['POST'])]
    #[McpToolRoute(description: 'Says a word back, as many times as asked.')]
    public function echo(string $word, Request $request): JsonResponse
    {
        $times = (int) (json_decode((string) $request->getContent(), true)['times'] ?? 1);

        if ($times < 1) {
            return new JsonResponse(['error' => 'times is at least 1'], 400);
        }

        return new JsonResponse(['said' => implode(' ', array_fill(0, $times, $word))]);
    }

    #[Route('/api/nothing', name: 'api_unlisted_tool', methods: ['GET'])]
    #[McpToolRoute]
    public function nothing(): JsonResponse
    {
        return new JsonResponse(['nothing' => true]);
    }

    #[Route('/api/unlisted', name: 'api_unlisted', methods: ['GET'])]
    public function unlisted(): JsonResponse
    {
        return new JsonResponse([]);
    }
}

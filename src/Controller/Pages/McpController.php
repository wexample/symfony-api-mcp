<?php

namespace Wexample\SymfonyApiMcp\Controller\Pages;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyApi\Service\ApiDocumentationService;
use Wexample\SymfonyApiMcp\Service\McpDocumentationService;
use Wexample\SymfonyApiMcp\Traits\SymfonyApiMcpBundleClassTrait;
use Wexample\SymfonyHelpers\Helper\VariableHelper;
use Wexample\SymfonyLoader\Controller\AbstractPagesController;

/**
 * The MCP server's page, beside the api's: where an agent connects and the
 * tools it finds there. Read by whoever reads the whole of the api's
 * documentation (`documentation.access_role`), a preview's reader refused.
 * Under /api/doc/, which the application keeps off its token firewall, as it
 * does Swagger UI.
 */
#[Route(path: 'api/doc/mcp', name: 'api_doc_mcp_')]
final class McpController extends AbstractPagesController
{
    use SymfonyApiMcpBundleClassTrait;

    final public const ROUTE_INDEX = 'api_doc_mcp_' . VariableHelper::INDEX;

    #[Route(name: VariableHelper::INDEX)]
    public function index(
        ApiDocumentationService $documentation,
        McpDocumentationService $mcp,
    ): Response {
        if (null !== $role = $documentation->getAccessRole()) {
            $this->denyAccessUnlessGranted($role);
        }

        return $this->renderPage(
            VariableHelper::INDEX,
            ['server' => $mcp->describe()]
        );
    }
}

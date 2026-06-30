<?php

namespace Webmail\Addons\Chat\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\Chat\Services\CategoryService;

/**
 * CategoryController - REST API for Channel Categories
 */
class CategoryController extends BaseController
{
    private ?CategoryService $categoryService = null;

    public function __construct(array $config)
    {
        parent::__construct($config);
    }

    private function getCategoryService(): ?CategoryService
    {
        if (!$this->categoryService) {
            try {
                $this->categoryService = new CategoryService($this->config);
            } catch (\Throwable $e) {
                error_log('CategoryController: Failed to init CategoryService: ' . $e->getMessage());
            }
        }
        return $this->categoryService;
    }

    private function requireCategoryAuth(Request $request): ?Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (!$this->getCategoryService()) {
            return Response::json(['error' => 'Category service unavailable'], 503);
        }
        return null;
    }

    /**
     * GET /chat/categories
     */
    public function listCategories(Request $request): Response
    {
        if ($error = $this->requireCategoryAuth($request)) return $error;

        $result = $this->categoryService->listCategories($this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json([
            'success' => true,
            'data' => [
                'categories' => $result['categories'],
                'uncategorized' => $result['uncategorized'],
            ]
        ]);
    }

    /**
     * POST /chat/categories
     */
    public function createCategory(Request $request): Response
    {
        if ($error = $this->requireCategoryAuth($request)) return $error;

        $body = $request->input();
        $name = $body['name'] ?? '';

        $result = $this->categoryService->createCategory($this->userEmail, $name);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json([
            'success' => true,
            'data' => ['category' => $result['category']]
        ]);
    }

    /**
     * PATCH /chat/categories/{id}
     */
    public function updateCategory(Request $request): Response
    {
        if ($error = $this->requireCategoryAuth($request)) return $error;

        $categoryId = (int)$request->getParam('id');
        if (!$categoryId) {
            return Response::json(['success' => false, 'error' => 'Category ID required'], 400);
        }

        $body = $request->input();
        $result = $this->categoryService->updateCategory($this->userEmail, $categoryId, $body);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json([
            'success' => true,
            'data' => ['category' => $result['category']]
        ]);
    }

    /**
     * DELETE /chat/categories/{id}
     */
    public function deleteCategory(Request $request): Response
    {
        if ($error = $this->requireCategoryAuth($request)) return $error;

        $categoryId = (int)$request->getParam('id');
        if (!$categoryId) {
            return Response::json(['success' => false, 'error' => 'Category ID required'], 400);
        }

        $result = $this->categoryService->deleteCategory($this->userEmail, $categoryId);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true]);
    }

    /**
     * POST /chat/categories/reorder
     */
    public function reorder(Request $request): Response
    {
        if ($error = $this->requireCategoryAuth($request)) return $error;

        $body = $request->input();
        $categories = $body['categories'] ?? [];

        if (empty($categories)) {
            return Response::json(['success' => false, 'error' => 'Categories data required'], 400);
        }

        $result = $this->categoryService->reorder($this->userEmail, $categories);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true]);
    }

    /**
     * POST /chat/channels/{id}/category
     */
    public function assignChannel(Request $request): Response
    {
        if ($error = $this->requireCategoryAuth($request)) return $error;

        $channelId = (int)$request->getParam('id');
        if (!$channelId) {
            return Response::json(['success' => false, 'error' => 'Channel ID required'], 400);
        }

        $body = $request->input();
        $categoryId = isset($body['category_id']) ? (int)$body['category_id'] : null;
        if ($categoryId === 0) $categoryId = null;

        $result = $this->categoryService->assignChannel($this->userEmail, $channelId, $categoryId);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true]);
    }
}

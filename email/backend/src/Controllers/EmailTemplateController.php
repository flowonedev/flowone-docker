<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\EmailTemplateService;

class EmailTemplateController extends BaseController
{
    private ?EmailTemplateService $templateService = null;

    private function getService(): EmailTemplateService
    {
        if (!$this->templateService) {
            $this->templateService = new EmailTemplateService($this->config);
        }
        return $this->templateService;
    }

    /**
     * List all templates accessible by user
     * GET /email-templates
     */
    public function list(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $templates = $this->getService()->list($this->userEmail);

        return Response::success([
            'templates' => $templates,
        ]);
    }

    /**
     * Get a single template
     * GET /email-templates/{id}
     */
    public function get(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $id = (int) $request->param('id');
        $template = $this->getService()->get($id, $this->userEmail);

        if (!$template) {
            return Response::error('Template not found', 404);
        }

        return Response::success(['template' => $template]);
    }

    /**
     * Create a new template
     * POST /email-templates
     */
    public function create(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $validationError = $this->validateRequired($request, ['name', 'html_content']);
        if ($validationError) return $validationError;

        $data = [
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'category' => $request->input('category', 'custom'),
            'icon' => $request->input('icon', 'dashboard_customize'),
            'html_content' => $request->input('html_content'),
            'thumbnail' => $request->input('thumbnail'),
            'is_shared' => $request->input('is_shared', 0),
            'sort_order' => $request->input('sort_order', 0),
        ];

        $template = $this->getService()->create($this->userEmail, $data);

        if (!$template) {
            return Response::error('Failed to create template', 500);
        }

        return Response::success(['template' => $template], 201);
    }

    /**
     * Update a template
     * PUT /email-templates/{id}
     */
    public function update(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $id = (int) $request->param('id');

        $data = [];
        $fields = ['name', 'description', 'category', 'icon', 'html_content', 'thumbnail', 'is_shared', 'sort_order'];
        foreach ($fields as $field) {
            $value = $request->input($field);
            if ($value !== null) {
                $data[$field] = $value;
            }
        }

        $template = $this->getService()->update($id, $this->userEmail, $data);

        if (!$template) {
            return Response::error('Template not found or access denied', 404);
        }

        return Response::success(['template' => $template]);
    }

    /**
     * Delete a template
     * DELETE /email-templates/{id}
     */
    public function delete(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $id = (int) $request->param('id');

        $deleted = $this->getService()->delete($id, $this->userEmail);

        if (!$deleted) {
            return Response::error('Template not found or access denied', 404);
        }

        return Response::success(['deleted' => true]);
    }

    /**
     * Reorder templates
     * POST /email-templates/reorder
     */
    public function reorder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $order = $request->input('order');
        if (!is_array($order)) {
            return Response::error('order must be an array of template IDs', 400);
        }

        $success = $this->getService()->reorder($this->userEmail, $order);

        return $success
            ? Response::success(['reordered' => true])
            : Response::error('Failed to reorder templates', 500);
    }
}


<?php

namespace Webmail\Addons\CrmPro\Controllers;

use Webmail\Controllers\BaseController;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\CrmPro\Services\CrmAutomationService;
use Webmail\Addons\CrmPro\Services\CrmSequenceService;

/**
 * CrmAutomationController
 * 
 * API endpoints for automation rules, execution log, sequences, and enrollments.
 */
class CrmAutomationController extends BaseController
{
    private CrmAutomationService $automationService;
    private CrmSequenceService $sequenceService;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->automationService = new CrmAutomationService($config);
        $this->sequenceService = new CrmSequenceService($config);
    }

    // =========================================================================
    // Automation Rules
    // =========================================================================

    /**
     * List all automation rules
     * GET /crm/automation/rules
     */
    public function listRules(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $rules = $this->automationService->listRules($this->userEmail);
        return Response::success(['rules' => $rules]);
    }

    /**
     * Create a new automation rule
     * POST /crm/automation/rules
     */
    public function createRule(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $body = $request->input();

        if (empty($body['name']) || empty($body['trigger_type']) || empty($body['action_type'])) {
            return Response::badRequest('Name, trigger_type, and action_type are required');
        }

        try {
            $rule = $this->automationService->createRule($this->userEmail, $body);
            return Response::success(['rule' => $rule], 201);
        } catch (\Throwable $e) {
            error_log("CrmAutomationController::createRule error: " . $e->getMessage());
            return Response::serverError('Failed to create automation rule');
        }
    }

    /**
     * Update an automation rule
     * PUT /crm/automation/rules/{id}
     */
    public function updateRule(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$id) return Response::badRequest('Rule ID required');

        $body = $request->input();

        try {
            $rule = $this->automationService->updateRule($id, $this->userEmail, $body);
            if (!$rule) return Response::notFound('Rule not found');
            return Response::success(['rule' => $rule]);
        } catch (\Throwable $e) {
            error_log("CrmAutomationController::updateRule error: " . $e->getMessage());
            return Response::serverError('Failed to update automation rule');
        }
    }

    /**
     * Delete an automation rule
     * DELETE /crm/automation/rules/{id}
     */
    public function deleteRule(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$id) return Response::badRequest('Rule ID required');

        $deleted = $this->automationService->deleteRule($id, $this->userEmail);
        if (!$deleted) return Response::notFound('Rule not found');

        return Response::success(['message' => 'Rule deleted']);
    }

    /**
     * Toggle a rule on/off
     * POST /crm/automation/rules/{id}/toggle
     */
    public function toggleRule(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$id) return Response::badRequest('Rule ID required');

        $rule = $this->automationService->toggleRule($id, $this->userEmail);
        if (!$rule) return Response::notFound('Rule not found');

        return Response::success(['rule' => $rule]);
    }

    /**
     * Test-fire a rule (ignores debounce, sends to the configured action target)
     * POST /crm/automation/rules/{id}/test
     */
    public function testRule(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$id) return Response::badRequest('Rule ID required');

        $rule = $this->automationService->getRule($id, $this->userEmail);
        if (!$rule) return Response::notFound('Rule not found');

        try {
            $this->automationService->testFireRule($id, $this->userEmail);
            return Response::success([
                'message' => 'Test fired successfully',
                'rule' => $rule,
            ]);
        } catch (\Throwable $e) {
            return Response::serverError('Test fire failed: ' . $e->getMessage());
        }
    }

    /**
     * Get automation execution log
     * GET /crm/automation/log
     */
    public function getLog(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $limit = (int)($request->getQuery('limit') ?? 100);
        $offset = (int)($request->getQuery('offset') ?? 0);

        $log = $this->automationService->getLog($this->userEmail, $limit, $offset);
        return Response::success(['log' => $log]);
    }

    // =========================================================================
    // Rule Sharing
    // =========================================================================

    /**
     * GET /crm/automation/rules/{id}/shares - Get sharing details for a rule
     */
    public function getRuleShares(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$id) return Response::badRequest('Rule ID required');

        $shares = $this->automationService->getRuleShares($id, $this->userEmail);
        if (!$shares) return Response::notFound('Rule not found or access denied');

        return Response::success($shares);
    }

    /**
     * POST /crm/automation/rules/{id}/duplicate - Copy a shared rule to own rules
     */
    public function duplicateRule(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$id) return Response::badRequest('Rule ID required');

        try {
            $newRule = $this->automationService->duplicateRule($id, $this->userEmail);
            if (!$newRule) return Response::notFound('Rule not found or access denied');

            return Response::success(['rule' => $newRule], 201);
        } catch (\Throwable $e) {
            return Response::serverError('Failed to duplicate rule: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // Sequences
    // =========================================================================

    /**
     * List all sequences
     * GET /crm/sequences
     */
    public function listSequences(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $sequences = $this->sequenceService->listSequences($this->userEmail);
        return Response::success(['sequences' => $sequences]);
    }

    /**
     * Create a new sequence
     * POST /crm/sequences
     */
    public function createSequence(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $body = $request->input();

        if (empty($body['name'])) {
            return Response::badRequest('Sequence name is required');
        }
        if (empty($body['steps']) || !is_array($body['steps'])) {
            return Response::badRequest('At least one step is required');
        }

        try {
            $sequence = $this->sequenceService->createSequence($this->userEmail, $body);
            return Response::success(['sequence' => $sequence], 201);
        } catch (\Throwable $e) {
            error_log("CrmAutomationController::createSequence error: " . $e->getMessage());
            return Response::serverError('Failed to create sequence');
        }
    }

    /**
     * Update a sequence
     * PUT /crm/sequences/{id}
     */
    public function updateSequence(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$id) return Response::badRequest('Sequence ID required');

        $body = $request->input();

        try {
            $sequence = $this->sequenceService->updateSequence($id, $this->userEmail, $body);
            if (!$sequence) return Response::notFound('Sequence not found');
            return Response::success(['sequence' => $sequence]);
        } catch (\Throwable $e) {
            error_log("CrmAutomationController::updateSequence error: " . $e->getMessage());
            return Response::serverError('Failed to update sequence');
        }
    }

    /**
     * Delete a sequence
     * DELETE /crm/sequences/{id}
     */
    public function deleteSequence(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$id) return Response::badRequest('Sequence ID required');

        $deleted = $this->sequenceService->deleteSequence($id, $this->userEmail);
        if (!$deleted) return Response::notFound('Sequence not found');

        return Response::success(['message' => 'Sequence deleted']);
    }

    /**
     * Manually enroll a deal/client in a sequence
     * POST /crm/sequences/{id}/enroll
     */
    public function enrollInSequence(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$id) return Response::badRequest('Sequence ID required');

        $body = $request->input();
        $clientId = (int)($body['client_id'] ?? 0);
        $dealId = !empty($body['deal_id']) ? (int)$body['deal_id'] : null;

        if (!$clientId) return Response::badRequest('client_id is required');

        try {
            $enrollment = $this->sequenceService->enrollInSequence($id, $clientId, $this->userEmail, $dealId);
            if (!$enrollment) return Response::badRequest('Already enrolled or sequence inactive');
            return Response::success(['enrollment' => $enrollment], 201);
        } catch (\Throwable $e) {
            error_log("CrmAutomationController::enrollInSequence error: " . $e->getMessage());
            return Response::serverError('Failed to enroll in sequence');
        }
    }

    /**
     * Get enrollments for a sequence
     * GET /crm/sequences/{id}/enrollments
     */
    public function getEnrollments(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$id) return Response::badRequest('Sequence ID required');

        $enrollments = $this->sequenceService->getEnrollments($id, $this->userEmail);
        return Response::success(['enrollments' => $enrollments]);
    }

    /**
     * Cancel an enrollment
     * POST /crm/sequences/enrollments/{id}/cancel
     */
    public function cancelEnrollment(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$id) return Response::badRequest('Enrollment ID required');

        $cancelled = $this->sequenceService->cancelEnrollment($id, $this->userEmail);
        if (!$cancelled) return Response::notFound('Enrollment not found or not active');

        return Response::success(['message' => 'Enrollment cancelled']);
    }
}


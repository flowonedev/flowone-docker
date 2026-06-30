<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class ModsecController extends BaseController
{
    public function status(Request $request): Response
    {
        return $this->agentAction('modsec.status');
    }

    public function setMode(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['mode']);
        if ($validation) return $validation;

        $mode = $request->input('mode');
        
        if (!in_array($mode, ['On', 'Off', 'DetectionOnly'])) {
            return Response::validationError(['mode' => 'Mode must be On, Off, or DetectionOnly']);
        }

        $result = $this->agent->execute('modsec.setMode', [
            'mode' => $mode,
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('modsec.set_mode', $mode, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'ModSecurity mode updated')
            : Response::error($result['error']);
    }

    public function rules(Request $request): Response
    {
        return $this->agentAction('modsec.rules');
    }

    public function enableRule(Request $request): Response
    {
        $rule = $request->getParam('rule');
        
        $result = $this->agent->execute('modsec.enableRule', [
            'rule' => $rule,
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('modsec.enable_rule', $rule, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Rule enabled')
            : Response::error($result['error']);
    }

    public function disableRule(Request $request): Response
    {
        $rule = $request->getParam('rule');
        
        $result = $this->agent->execute('modsec.disableRule', [
            'rule' => $rule,
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('modsec.disable_rule', $rule, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Rule disabled')
            : Response::error($result['error']);
    }

    public function auditLog(Request $request): Response
    {
        return $this->agentAction('modsec.auditLog', [
            'limit' => $request->getQuery('limit', 100),
        ]);
    }
}


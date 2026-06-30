<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class FirewallController extends BaseController
{
    public function status(Request $request): Response
    {
        return $this->agentAction('firewall.status');
    }

    public function zones(Request $request): Response
    {
        return $this->agentAction('firewall.zones');
    }

    public function zone(Request $request): Response
    {
        $name = $request->getParam('name');
        return $this->agentAction('firewall.zone', ['name' => $name]);
    }

    public function addService(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['service']);
        if ($validation) return $validation;

        $result = $this->agent->execute('firewall.addService', [
            'service' => $request->input('service'),
            'zone' => $request->input('zone'),
            'permanent' => $request->input('permanent', true),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('firewall.add_service', $request->input('service'), 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Service added')
            : Response::error($result['error']);
    }

    public function removeService(Request $request): Response
    {
        $service = $request->getParam('service');
        
        $result = $this->agent->execute('firewall.removeService', [
            'service' => $service,
            'zone' => $request->input('zone'),
            'permanent' => $request->input('permanent', true),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('firewall.remove_service', $service, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Service removed')
            : Response::error($result['error']);
    }

    public function addPort(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['port', 'protocol']);
        if ($validation) return $validation;

        $result = $this->agent->execute('firewall.addPort', [
            'port' => (int)$request->input('port'),
            'protocol' => $request->input('protocol'),
            'zone' => $request->input('zone'),
            'permanent' => $request->input('permanent', true),
        ], $this->getActor());

        $target = $request->input('port') . '/' . $request->input('protocol');
        
        if ($result['success']) {
            $this->logAction('firewall.add_port', $target, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Port added')
            : Response::error($result['error']);
    }

    public function removePort(Request $request): Response
    {
        $port = (int)$request->getParam('port');
        $protocol = $request->getParam('protocol');
        
        $result = $this->agent->execute('firewall.removePort', [
            'port' => $port,
            'protocol' => $protocol,
            'zone' => $request->input('zone'),
            'permanent' => $request->input('permanent', true),
        ], $this->getActor());

        $target = "{$port}/{$protocol}";
        
        if ($result['success']) {
            $this->logAction('firewall.remove_port', $target, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Port removed')
            : Response::error($result['error']);
    }

    public function richRules(Request $request): Response
    {
        return $this->agentAction('firewall.richRules', [
            'zone' => $request->getQuery('zone'),
        ]);
    }

    public function addRichRule(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['rule']);
        if ($validation) return $validation;

        $result = $this->agent->execute('firewall.addRichRule', [
            'rule' => $request->input('rule'),
            'zone' => $request->input('zone'),
            'permanent' => $request->input('permanent', true),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('firewall.add_rich_rule', 'rule', 'success', ['rule' => $request->input('rule')]);
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Rich rule added')
            : Response::error($result['error']);
    }

    public function removeRichRule(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['rule']);
        if ($validation) return $validation;

        $result = $this->agent->execute('firewall.removeRichRule', [
            'rule' => $request->input('rule'),
            'zone' => $request->input('zone'),
            'permanent' => $request->input('permanent', true),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('firewall.remove_rich_rule', 'rule', 'success', ['rule' => $request->input('rule')]);
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Rich rule removed')
            : Response::error($result['error']);
    }

    public function reload(Request $request): Response
    {
        $result = $this->agent->execute('firewall.reload', [], $this->getActor());

        if ($result['success']) {
            $this->logAction('firewall.reload', 'firewall', 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Firewall reloaded')
            : Response::error($result['error']);
    }
}


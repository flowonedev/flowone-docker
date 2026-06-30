<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class Fail2banController extends BaseController
{
    public function status(Request $request): Response
    {
        return $this->agentAction('fail2ban.status');
    }

    public function jails(Request $request): Response
    {
        return $this->agentAction('fail2ban.jails');
    }

    public function jail(Request $request): Response
    {
        $name = $request->getParam('name');
        return $this->agentAction('fail2ban.jail', ['name' => $name]);
    }

    public function banned(Request $request): Response
    {
        $name = $request->getQuery('jail');
        $params = $name ? ['name' => $name] : [];
        return $this->agentAction('fail2ban.banned', $params);
    }

    public function ban(Request $request): Response
    {
        $jail = $request->getParam('name');
        $validation = $this->validateRequired($request, ['ip']);
        if ($validation) return $validation;

        $result = $this->agent->execute('fail2ban.ban', [
            'jail' => $jail,
            'ip' => $request->input('ip'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('fail2ban.ban', $request->input('ip'), 'success', ['jail' => $jail]);
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'IP banned')
            : Response::error($result['error']);
    }

    public function unban(Request $request): Response
    {
        $jail = $request->getParam('name');
        $validation = $this->validateRequired($request, ['ip']);
        if ($validation) return $validation;

        $result = $this->agent->execute('fail2ban.unban', [
            'jail' => $jail,
            'ip' => $request->input('ip'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('fail2ban.unban', $request->input('ip'), 'success', ['jail' => $jail]);
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'IP unbanned')
            : Response::error($result['error']);
    }

    public function createJail(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['name']);
        if ($validation) return $validation;

        $result = $this->agent->execute('fail2ban.createJail', [
            'name' => $request->input('name'),
            'enabled' => $request->input('enabled', 'true'),
            'port' => $request->input('port', 'http,https'),
            'filter' => $request->input('filter'),
            'logpath' => $request->input('logpath'),
            'maxretry' => $request->input('maxretry', 5),
            'bantime' => $request->input('bantime', '10m'),
            'findtime' => $request->input('findtime', '10m'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('fail2ban.create_jail', $request->input('name'), 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Jail created')
            : Response::error($result['error']);
    }

    public function updateJail(Request $request): Response
    {
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('fail2ban.updateJail', [
            'name' => $name,
            'enabled' => $request->input('enabled'),
            'port' => $request->input('port'),
            'filter' => $request->input('filter'),
            'logpath' => $request->input('logpath'),
            'maxretry' => $request->input('maxretry'),
            'bantime' => $request->input('bantime'),
            'findtime' => $request->input('findtime'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('fail2ban.update_jail', $name, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Jail updated')
            : Response::error($result['error']);
    }

    public function enableJail(Request $request): Response
    {
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('fail2ban.enableJail', [
            'name' => $name,
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('fail2ban.enable_jail', $name, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Jail enabled')
            : Response::error($result['error']);
    }

    public function disableJail(Request $request): Response
    {
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('fail2ban.disableJail', [
            'name' => $name,
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('fail2ban.disable_jail', $name, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Jail disabled')
            : Response::error($result['error']);
    }

    public function deleteJail(Request $request): Response
    {
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('fail2ban.deleteJail', [
            'name' => $name,
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('fail2ban.delete_jail', $name, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Jail deleted')
            : Response::error($result['error']);
    }
}


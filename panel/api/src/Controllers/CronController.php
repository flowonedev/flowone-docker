<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class CronController extends BaseController
{
    public function index(Request $request): Response
    {
        return $this->agentAction('cron.list');
    }

    public function show(Request $request): Response
    {
        $id = $request->getParam('id');
        return $this->agentAction('cron.get', ['id' => $id]);
    }

    public function create(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['schedule', 'command']);
        if ($validation) return $validation;

        $result = $this->agent->execute('cron.create', [
            'name' => $request->input('name'),
            'schedule' => $request->input('schedule'),
            'command' => $request->input('command'),
            'description' => $request->input('description'),
            'user' => $request->input('user', 'root'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('cron.create', $request->input('name') ?? 'job', 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Cron job created')
            : Response::error($result['error']);
    }

    public function update(Request $request): Response
    {
        $id = $request->getParam('id');
        
        $result = $this->agent->execute('cron.update', [
            'id' => $id,
            'schedule' => $request->input('schedule'),
            'command' => $request->input('command'),
            'description' => $request->input('description'),
            'user' => $request->input('user'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('cron.update', $id, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Cron job updated')
            : Response::error($result['error']);
    }

    public function delete(Request $request): Response
    {
        $id = $request->getParam('id');
        
        $result = $this->agent->execute('cron.delete', [
            'id' => $id,
            'force' => $request->input('force', false),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('cron.delete', $id, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Cron job deleted')
            : Response::error($result['error']);
    }

    public function toggle(Request $request): Response
    {
        $id = $request->getParam('id');
        
        $result = $this->agent->execute('cron.toggle', [
            'id' => $id,
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('cron.toggle', $id, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Cron job toggled')
            : Response::error($result['error']);
    }

    public function logs(Request $request): Response
    {
        return $this->agentAction('cron.logs', [
            'lines' => $request->input('lines', 100),
            'filter' => $request->input('filter'),
        ]);
    }
}


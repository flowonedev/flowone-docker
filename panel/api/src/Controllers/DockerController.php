<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class DockerController extends BaseController
{
    /**
     * Get Docker status
     */
    public function status(Request $request): Response
    {
        $result = $this->agent->execute('docker.status', [], $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to get Docker status');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Install Docker
     */
    public function install(Request $request): Response
    {
        // Only super_admin can install Docker
        if (!$this->isSuperAdmin()) {
            return Response::error('Only super admins can install Docker', 403);
        }

        $result = $this->agent->execute('docker.install', [
            'include_compose' => $request->input('include_compose', true),
        ], $this->getActor());

        $this->logAction('docker.install', 'system', $result['success'] ? 'success' : 'failed', [
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to install Docker');
        }

        return Response::success($result['data'], $result['message'] ?? 'Docker installed successfully');
    }

    /**
     * Deep engine snapshot: info, containers, images, volumes, networks,
     * disk usage and compose stacks — feeds the Docker details page.
     */
    public function overview(Request $request): Response
    {
        $result = $this->agent->execute('docker.overview', [], $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to get Docker overview');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * List all volumes
     */
    public function volumes(Request $request): Response
    {
        $result = $this->agent->execute('docker.volumes', [], $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to list volumes');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * List all networks
     */
    public function networks(Request $request): Response
    {
        $result = $this->agent->execute('docker.networks', [], $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to list networks');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * List all containers
     */
    public function containers(Request $request): Response
    {
        $result = $this->agent->execute('docker.containers', [
            'all' => $request->getQuery('all', 'true') === 'true',
        ], $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to list containers');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * List all images
     */
    public function images(Request $request): Response
    {
        $result = $this->agent->execute('docker.images', [], $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to list images');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Get container details
     */
    public function container(Request $request): Response
    {
        $id = $request->getParam('id');
        
        if (!$id) {
            return Response::error('Container ID is required');
        }

        $result = $this->agent->execute('docker.container', ['id' => $id], $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to get container');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Start a container
     */
    public function start(Request $request): Response
    {
        $id = $request->getParam('id');
        
        if (!$id) {
            return Response::error('Container ID is required');
        }

        $result = $this->agent->execute('docker.start', ['id' => $id], $this->getActor());

        $this->logAction('docker.start', $id, $result['success'] ? 'success' : 'failed');

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to start container');
        }

        return Response::success($result['data'], $result['message'] ?? 'Container started');
    }

    /**
     * Stop a container
     */
    public function stop(Request $request): Response
    {
        $id = $request->getParam('id');
        
        if (!$id) {
            return Response::error('Container ID is required');
        }

        $result = $this->agent->execute('docker.stop', [
            'id' => $id,
            'timeout' => $request->input('timeout', 10),
        ], $this->getActor());

        $this->logAction('docker.stop', $id, $result['success'] ? 'success' : 'failed');

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to stop container');
        }

        return Response::success($result['data'], $result['message'] ?? 'Container stopped');
    }

    /**
     * Restart a container
     */
    public function restart(Request $request): Response
    {
        $id = $request->getParam('id');
        
        if (!$id) {
            return Response::error('Container ID is required');
        }

        $result = $this->agent->execute('docker.restart', [
            'id' => $id,
            'timeout' => $request->input('timeout', 10),
        ], $this->getActor());

        $this->logAction('docker.restart', $id, $result['success'] ? 'success' : 'failed');

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to restart container');
        }

        return Response::success($result['data'], $result['message'] ?? 'Container restarted');
    }

    /**
     * Get container logs
     */
    public function logs(Request $request): Response
    {
        $id = $request->getParam('id');
        
        if (!$id) {
            return Response::error('Container ID is required');
        }

        $result = $this->agent->execute('docker.logs', [
            'id' => $id,
            'lines' => $request->getQuery('lines', 100),
        ], $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to get logs');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Get container stats
     */
    public function stats(Request $request): Response
    {
        $id = $request->getParam('id');

        $result = $this->agent->execute('docker.stats', [
            'id' => $id,
        ], $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to get stats');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Inspect container/image
     */
    public function inspect(Request $request): Response
    {
        $id = $request->getParam('id');
        
        if (!$id) {
            return Response::error('Container/Image ID is required');
        }

        $result = $this->agent->execute('docker.inspect', ['id' => $id], $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to inspect');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Remove a container
     */
    public function remove(Request $request): Response
    {
        $id = $request->getParam('id');
        
        if (!$id) {
            return Response::error('Container ID is required');
        }

        $result = $this->agent->execute('docker.remove', [
            'id' => $id,
            'force' => $request->input('force', false),
            'remove_volumes' => $request->input('remove_volumes', false),
        ], $this->getActor());

        $this->logAction('docker.remove', $id, $result['success'] ? 'success' : 'failed');

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to remove container');
        }

        return Response::success($result['data'], $result['message'] ?? 'Container removed');
    }

    /**
     * Pull an image
     */
    public function pull(Request $request): Response
    {
        $image = $request->input('image');
        
        if (!$image) {
            return Response::error('Image name is required');
        }

        $result = $this->agent->execute('docker.pull', ['image' => $image], $this->getActor());

        $this->logAction('docker.pull', $image, $result['success'] ? 'success' : 'failed');

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to pull image');
        }

        return Response::success($result['data'], $result['message'] ?? 'Image pulled');
    }

    /**
     * Docker Compose up
     */
    public function composeUp(Request $request): Response
    {
        $path = $request->input('path');
        
        if (!$path) {
            return Response::error('Path is required');
        }

        $result = $this->agent->execute('docker.composeUp', [
            'path' => $path,
            'file' => $request->input('file', 'docker-compose.yml'),
            'detached' => $request->input('detached', true),
        ], $this->getActor());

        $this->logAction('docker.composeUp', $path, $result['success'] ? 'success' : 'failed');

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to start compose');
        }

        return Response::success($result['data'], $result['message'] ?? 'Compose started');
    }

    /**
     * Docker Compose down
     */
    public function composeDown(Request $request): Response
    {
        $path = $request->input('path');
        
        if (!$path) {
            return Response::error('Path is required');
        }

        $result = $this->agent->execute('docker.composeDown', [
            'path' => $path,
            'file' => $request->input('file', 'docker-compose.yml'),
            'remove_volumes' => $request->input('remove_volumes', false),
        ], $this->getActor());

        $this->logAction('docker.composeDown', $path, $result['success'] ? 'success' : 'failed');

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to stop compose');
        }

        return Response::success($result['data'], $result['message'] ?? 'Compose stopped');
    }
}


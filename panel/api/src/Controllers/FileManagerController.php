<?php
/**
 * File Manager Controller
 * 
 * Handles file system operations API endpoints
 */

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class FileManagerController extends BaseController
{
    /**
     * List directory contents
     */
    public function list(Request $request): Response
    {
        $path = $request->input('path') ?? '/home';
        $showHidden = filter_var($request->input('show_hidden'), FILTER_VALIDATE_BOOLEAN);
        $forceRefresh = $request->input('refresh') === '1';
        
        // Generate cache key based on path and hidden flag
        $cacheKey = 'files:' . md5($path . ':' . ($showHidden ? '1' : '0'));
        
        // Try cache first (unless force refresh)
        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                // Apply sorting client-side from cached data
                $cached['from_cache'] = true;
                return Response::success($cached, 'Success');
            }
        }
        
        $params = [
            'path' => $path,
            'show_hidden' => $showHidden,
            'sort_by' => $request->input('sort_by') ?? 'name',
            'sort_dir' => $request->input('sort_dir') ?? 'asc',
        ];

        $result = $this->agent->execute('filemanager.list', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to list directory');
        }

        // Cache the result (30 second TTL for files)
        $this->cache->set($cacheKey, $result['data'], 30);
        
        return Response::success($result['data'], $result['message'] ?? 'Success');
    }
    
    /**
     * Invalidate cache for a path and its parent
     */
    private function invalidatePathCache(string $path): void
    {
        // Invalidate this path
        $this->cache->invalidateFiles($path);
        
        // Also invalidate parent directory
        $parent = dirname($path);
        if ($parent !== $path) {
            $this->cache->invalidateFiles($parent);
        }
    }

    /**
     * Read file contents
     */
    public function read(Request $request): Response
    {
        $path = $request->input('path');
        
        if (!$path) {
            return Response::error('Path is required');
        }

        $params = [
            'path' => $path,
            'encoding' => $request->input('encoding') ?? 'utf-8',
            'max_size' => (int) ($request->input('max_size') ?? 5242880), // 5MB
        ];

        $result = $this->agent->execute('filemanager.read', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to read file');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Write file contents
     */
    public function write(Request $request): Response
    {
        $path = $request->input('path');
        
        if (!$path) {
            return Response::error('Path is required');
        }

        $params = [
            'path' => $path,
            'content' => $request->input('content') ?? '',
            'encoding' => $request->input('encoding') ?? 'utf-8',
            'create_dirs' => filter_var($request->input('create_dirs'), FILTER_VALIDATE_BOOLEAN),
        ];

        $result = $this->agent->execute('filemanager.write', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to write file');
        }

        // Invalidate cache for this path
        $this->invalidatePathCache($path);

        return Response::success($result['data'], $result['message'] ?? 'File saved');
    }

    /**
     * Create directory
     */
    public function mkdir(Request $request): Response
    {
        $path = $request->input('path');
        
        if (!$path) {
            return Response::error('Path is required');
        }

        $params = [
            'path' => $path,
            'recursive' => filter_var($request->input('recursive') ?? true, FILTER_VALIDATE_BOOLEAN),
        ];

        $result = $this->agent->execute('filemanager.mkdir', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to create directory');
        }

        // Invalidate cache for this path
        $this->invalidatePathCache($path);

        return Response::success($result['data'], $result['message'] ?? 'Directory created');
    }

    /**
     * Delete file or directory
     */
    public function delete(Request $request): Response
    {
        $path = $request->input('path');
        
        if (!$path) {
            return Response::error('Path is required');
        }

        $params = [
            'path' => $path,
            'recursive' => filter_var($request->input('recursive'), FILTER_VALIDATE_BOOLEAN),
        ];

        $result = $this->agent->execute('filemanager.delete', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to delete');
        }

        // Invalidate cache for this path
        $this->invalidatePathCache($path);

        return Response::success($result['data'], $result['message'] ?? 'Deleted');
    }

    /**
     * Copy file or directory
     */
    public function copy(Request $request): Response
    {
        $source = $request->input('source');
        $destination = $request->input('destination');
        
        if (!$source || !$destination) {
            return Response::error('Source and destination are required');
        }

        $params = [
            'source' => $source,
            'destination' => $destination,
            'overwrite' => filter_var($request->input('overwrite'), FILTER_VALIDATE_BOOLEAN),
        ];

        $result = $this->agent->execute('filemanager.copy', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to copy');
        }

        // Invalidate cache for source and destination
        $this->invalidatePathCache($source);
        $this->invalidatePathCache($destination);

        return Response::success($result['data'], $result['message'] ?? 'Copied');
    }

    /**
     * Move file or directory
     */
    public function move(Request $request): Response
    {
        $source = $request->input('source');
        $destination = $request->input('destination');
        
        if (!$source || !$destination) {
            return Response::error('Source and destination are required');
        }

        $params = [
            'source' => $source,
            'destination' => $destination,
            'overwrite' => filter_var($request->input('overwrite'), FILTER_VALIDATE_BOOLEAN),
        ];

        $result = $this->agent->execute('filemanager.move', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to move');
        }

        // Invalidate cache for source and destination
        $this->invalidatePathCache($source);
        $this->invalidatePathCache($destination);

        return Response::success($result['data'], $result['message'] ?? 'Moved');
    }

    /**
     * Rename file or directory
     */
    public function rename(Request $request): Response
    {
        $path = $request->input('path');
        $newName = $request->input('new_name');
        
        if (!$path || !$newName) {
            return Response::error('Path and new name are required');
        }

        $params = [
            'path' => $path,
            'new_name' => $newName,
        ];

        $result = $this->agent->execute('filemanager.rename', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to rename');
        }

        // Invalidate cache for this path
        $this->invalidatePathCache($path);

        return Response::success($result['data'], $result['message'] ?? 'Renamed');
    }

    /**
     * Get or set permissions
     */
    public function permissions(Request $request): Response
    {
        $path = $request->input('path');
        
        if (!$path) {
            return Response::error('Path is required');
        }

        $params = [
            'path' => $path,
            'mode' => $request->input('mode'),
            'owner' => $request->input('owner'),
            'group' => $request->input('group'),
            'recursive' => filter_var($request->input('recursive'), FILTER_VALIDATE_BOOLEAN),
        ];

        $result = $this->agent->execute('filemanager.permissions', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to update permissions');
        }

        // Invalidate cache for this path (permissions change metadata)
        $this->invalidatePathCache($path);

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Get file/directory info
     */
    public function info(Request $request): Response
    {
        $path = $request->input('path');
        
        if (!$path) {
            return Response::error('Path is required');
        }

        $result = $this->agent->execute('filemanager.info', ['path' => $path], $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to get info');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Search for files
     */
    public function search(Request $request): Response
    {
        $params = [
            'path' => $request->input('path') ?? '/home',
            'pattern' => $request->input('pattern') ?? '*',
            'type' => $request->input('type'),
            'max_depth' => (int) ($request->input('max_depth') ?? 5),
            'limit' => (int) ($request->input('limit') ?? 100),
        ];

        $result = $this->agent->execute('filemanager.search', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Search failed');
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Compress files
     */
    public function compress(Request $request): Response
    {
        $paths = $request->input('paths');
        $destination = $request->input('destination');
        
        if (!$paths || !$destination) {
            return Response::error('Paths and destination are required');
        }

        $params = [
            'paths' => is_array($paths) ? $paths : [$paths],
            'destination' => $destination,
            'format' => $request->input('format') ?? 'zip',
        ];

        $result = $this->agent->execute('filemanager.compress', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Compression failed');
        }

        // Invalidate destination directory cache
        $this->invalidatePathCache(dirname($destination));

        return Response::success($result['data'], $result['message'] ?? 'Compressed');
    }

    /**
     * Extract archive
     */
    public function extract(Request $request): Response
    {
        $path = $request->input('path');
        
        if (!$path) {
            return Response::error('Path is required');
        }

        $params = [
            'path' => $path,
            'destination' => $request->input('destination'),
        ];

        $result = $this->agent->execute('filemanager.extract', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Extraction failed');
        }

        // Invalidate destination directory cache
        $destPath = $request->input('destination') ?? dirname($path);
        $this->invalidatePathCache($destPath);

        return Response::success($result['data'], $result['message'] ?? 'Extracted');
    }

    /**
     * Download file (returns file path for download)
     */
    public function download(Request $request): Response
    {
        $path = $request->input('path');
        
        if (!$path) {
            return Response::error('Path is required');
        }

        // First verify file exists and is accessible
        $result = $this->agent->execute('filemanager.info', ['path' => $path], $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'File not found');
        }

        $info = $result['data'];
        
        if ($info['type'] === 'directory') {
            return Response::error('Cannot download directory directly. Compress it first.');
        }

        // Return file info for download
        return Response::success([
            'path' => $info['real_path'],
            'name' => $info['name'],
            'size' => $info['size'],
            'mime_type' => $info['mime_type'] ?? 'application/octet-stream',
        ], 'Ready for download');
    }

    /**
     * Upload file
     */
    public function upload(Request $request): Response
    {
        $destination = $request->input('destination');
        $filename = $request->input('filename');
        $content = $request->input('content');
        $encoding = $request->input('encoding') ?? 'base64';
        
        if (!$destination || !$filename || !$content) {
            return Response::error('Destination, filename, and content are required');
        }

        $path = rtrim($destination, '/') . '/' . $filename;

        $params = [
            'path' => $path,
            'content' => $content,
            'encoding' => $encoding,
            'create_dirs' => true,
        ];

        $result = $this->agent->execute('filemanager.write', $params, $this->getActor());

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Upload failed');
        }

        // Invalidate destination directory cache
        $this->invalidatePathCache($destination);

        return Response::success($result['data'], $result['message'] ?? 'File uploaded');
    }
}


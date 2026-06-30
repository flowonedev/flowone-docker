<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Container;
use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use FleetManager\Api\Services\PackageService;

/**
 * Package Controller - Manages deployment packages (panel, email, agent)
 */
class PackageController extends BaseController
{
    private PackageService $packages;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->packages = $container->get(PackageService::class);
    }

    /**
     * GET /api/packages
     * List all packages grouped by type with stats and source info
     */
    public function index(Request $request): Response
    {
        try {
            $packages = $this->packages->listAll();
            $stats = $this->packages->getStats();
            $sourceInfo = $this->packages->getSourceInfo();

            return Response::success([
                'packages' => $packages,
                'stats' => $stats,
                'types' => PackageService::TYPES,
                'type_labels' => PackageService::TYPE_LABELS,
                'source_info' => $sourceInfo,
            ]);
        } catch (\Exception $e) {
            return Response::error('Failed to list packages: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/packages/{type}
     * List all versions of a specific package type
     */
    public function listVersions(Request $request, string $type): Response
    {
        if (!in_array($type, PackageService::TYPES)) {
            return Response::error("Invalid package type: {$type}. Valid types: " . implode(', ', PackageService::TYPES), 400);
        }

        try {
            $versions = $this->packages->listVersions($type);
            $latestVersion = $this->packages->getLatestVersion($type);

            return Response::success([
                'type' => $type,
                'versions' => $versions,
                'latest_version' => $latestVersion,
                'count' => count($versions),
            ]);
        } catch (\Exception $e) {
            return Response::error('Failed to list versions: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/packages/{type}/upload
     * Upload a new package version
     */
    public function upload(Request $request, string $type): Response
    {
        if (!in_array($type, PackageService::TYPES)) {
            return Response::error("Invalid package type: {$type}", 400);
        }

        // Get uploaded file
        $files = $request->getFiles();
        
        if (empty($files['package'])) {
            return Response::error('No package file uploaded. Use form field name: package', 400);
        }

        $file = $files['package'];

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
            ];
            $message = $errorMessages[$file['error']] ?? 'Unknown upload error';
            return Response::error("Upload failed: {$message}", 400);
        }

        // Optional version override
        $version = $request->input('version');

        try {
            $result = $this->packages->upload($type, $file, $version);

            $this->logAction('package.upload', null, "{$type}/{$result['version']}", 'success', [
                'type' => $type,
                'version' => $result['version'],
                'size' => $result['size'],
            ]);

            return Response::success($result, 'Package uploaded successfully');
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            $this->logAction('package.upload', null, $type, 'failed', [
                'error' => $e->getMessage(),
            ]);
            return Response::error('Failed to upload package: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/packages/{type}/build
     * Build a new package version from local installation
     */
    public function build(Request $request, string $type): Response
    {
        if (!in_array($type, PackageService::TYPES)) {
            return Response::error("Invalid package type: {$type}. Valid types: " . implode(', ', PackageService::TYPES), 400);
        }

        try {
            $result = $this->packages->build($type);

            $this->logAction('package.build', null, "{$type}/{$result['version']}", 'success', [
                'type' => $type,
                'version' => $result['version'],
                'size' => $result['size'],
                'files' => $result['contents']['files'] ?? 0,
                'source_path' => $result['source_path'],
            ]);

            return Response::success($result, "Package {$type} v{$result['version']} built successfully");
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            $this->logAction('package.build', null, $type, 'failed', [
                'error' => $e->getMessage(),
            ]);
            return Response::error('Build failed: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/packages/{type}/{version}/set-latest
     * Set a version as the latest
     */
    public function setLatest(Request $request, string $type, string $version): Response
    {
        if (!in_array($type, PackageService::TYPES)) {
            return Response::error("Invalid package type: {$type}", 400);
        }

        try {
            $this->packages->setLatest($type, $version);

            $this->logAction('package.set_latest', null, "{$type}/{$version}", 'success');

            return Response::success([
                'type' => $type,
                'version' => $version,
                'is_latest' => true,
            ], "Version {$version} is now the latest for {$type}");
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            return Response::error('Failed to set latest version: ' . $e->getMessage());
        }
    }

    /**
     * DELETE /api/packages/{type}/{version}
     * Delete a package version
     */
    public function delete(Request $request, string $type, string $version): Response
    {
        if (!in_array($type, PackageService::TYPES)) {
            return Response::error("Invalid package type: {$type}", 400);
        }

        // Prevent deleting the only version? Optional safety check
        $versions = $this->packages->listVersions($type);
        if (count($versions) === 1 && $versions[0]['version'] === $version) {
            // Allow deletion but warn
        }

        try {
            $this->packages->delete($type, $version);

            $this->logAction('package.delete', null, "{$type}/{$version}", 'success');

            return Response::success([
                'type' => $type,
                'version' => $version,
                'deleted' => true,
            ], "Package {$type} v{$version} deleted");
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            $this->logAction('package.delete', null, "{$type}/{$version}", 'failed', [
                'error' => $e->getMessage(),
            ]);
            return Response::error('Failed to delete package: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/packages/{type}/{version}
     * Get package info
     */
    public function show(Request $request, string $type, string $version): Response
    {
        if (!in_array($type, PackageService::TYPES)) {
            return Response::error("Invalid package type: {$type}", 400);
        }

        $package = $this->packages->getPackage($type, $version);

        if (!$package) {
            return Response::error("Package {$type} v{$version} not found", 404);
        }

        return Response::success($package);
    }

    /**
     * GET /api/packages/{type}/{version}/download
     * Download a package file
     */
    public function download(Request $request, string $type, string $version): Response
    {
        if (!in_array($type, PackageService::TYPES)) {
            return Response::error("Invalid package type: {$type}", 400);
        }

        $path = $this->packages->getFilePath($type, $version);

        if (!$path) {
            return Response::error("Package {$type} v{$version} not found", 404);
        }

        $this->logAction('package.download', null, "{$type}/{$version}", 'success');

        // Return file download response
        return Response::file($path, "{$type}-v{$version}.tar.gz");
    }
}


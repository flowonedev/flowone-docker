<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\DriveService;

/**
 * DriveVersionsController - REST API for Drive file version history.
 *
 * Owns every /drive/files/{id}/versions* route plus the account-wide
 * version usage/cleanup endpoints and the desktop client's content-commit
 * flow. Split out of DriveController (which was far past the size limit)
 * as part of the versioning overhaul.
 */
class DriveVersionsController extends BaseController
{
    private DriveService $driveService;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->driveService = new DriveService($config, $this->userEmail);
    }

    // ─────────────────────────────────────────────────────────────────────
    // History
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/drive/files/{id}/versions
     */
    public function getVersions(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');

        $versions = $this->driveService->versioning()->getFileVersions($activeEmail, $id);

        return Response::success(['versions' => $versions]);
    }

    /**
     * POST /api/drive/files/{id}/versions/{versionId}/restore
     */
    public function restoreVersion(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');
        $versionId = (int)$request->getParam('versionId');

        try {
            if (!$this->driveService->versioning()->restoreVersion($activeEmail, $fileId, $versionId)) {
                return Response::error('Version not found', 404);
            }
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 400);
        }

        return Response::success(null, 'Version restored');
    }

    /**
     * DELETE /api/drive/files/{id}/versions/{versionId}
     */
    public function deleteVersion(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');
        $versionId = (int)$request->getParam('versionId');

        if (!$this->driveService->versioning()->deleteVersion($activeEmail, $fileId, $versionId)) {
            return Response::error('Version not found', 404);
        }

        return Response::success(null, 'Version deleted');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pin / label
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /api/drive/files/{id}/versions/{versionId}/pin
     * Body: { pinned: bool }
     */
    public function pinVersion(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');
        $versionId = (int)$request->getParam('versionId');
        $pinned = (bool)$request->input('pinned');

        if (!$this->driveService->versioning()->setVersionPinned($activeEmail, $fileId, $versionId, $pinned)) {
            return Response::error('Version not found', 404);
        }

        return Response::success(['pinned' => $pinned], $pinned ? 'Version pinned' : 'Version unpinned');
    }

    /**
     * PATCH /api/drive/files/{id}/versions/{versionId}
     * Body: { label: string|null }
     */
    public function updateVersion(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');
        $versionId = (int)$request->getParam('versionId');
        $label = $request->input('label');

        if (!$this->driveService->versioning()->setVersionLabel($activeEmail, $fileId, $versionId, $label !== null ? (string)$label : null)) {
            return Response::error('Version not found', 404);
        }

        return Response::success(null, 'Version updated');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Usage / cleanup
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/drive/versions/usage
     */
    public function versionsUsage(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $usage = $this->driveService->versioning()->getVersionsUsage($this->getActiveEmail());

        return Response::success(['usage' => $usage]);
    }

    /**
     * POST /api/drive/files/{id}/versions/cleanup
     * Deletes every unpinned version of one file.
     */
    public function cleanupFileVersions(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');

        $result = $this->driveService->versioning()->cleanupFileVersions($activeEmail, $fileId);

        return Response::success($result, 'Versions cleaned up');
    }

    /**
     * POST /api/drive/versions/cleanup
     * Deletes every unpinned version of every file the user owns.
     */
    public function cleanupAllVersions(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $result = $this->driveService->versioning()->cleanupAllVersions($this->getActiveEmail());

        return Response::success($result, 'Versions cleaned up');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Desktop pre-overwrite snapshot (direct NAS write)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /api/drive/files/{id}/versions/snapshot
     *
     * The desktop client calls this BEFORE overwriting a NAS file in
     * place. The server copies the current bytes into the version store
     * and bumps current_version, so the overwrite that follows cannot
     * destroy history. Replaces the old (broken) behavior where
     * PUT /files/{id}/metadata inserted a version row pointing at
     * already-overwritten bytes.
     */
    public function snapshotVersion(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');

        $archive = $this->driveService->versioning()->snapshotCurrentVersion($activeEmail, $fileId);

        if (!$archive) {
            return Response::error('File not found or snapshot failed', 404);
        }

        return Response::success([
            'version_id' => $archive['version_id'],
            'version_number' => $archive['version_number'],
        ], 'Version snapshot created');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Download / preview
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/drive/files/{id}/versions/{versionId}/download
     */
    public function downloadVersion(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');
        $versionId = (int)$request->getParam('versionId');

        $fileInfo = $this->driveService->versioning()->getVersionFilePath($activeEmail, $fileId, $versionId);

        if (!$fileInfo) {
            return Response::error('Version not found', 404);
        }

        $downloadName = 'v' . $fileInfo['version_number'] . '_' . $fileInfo['filename'];
        // streamBinaryFile quotes the name naively; strip header-breaking chars.
        $downloadName = str_replace(["\r", "\n", "\0", '"', '\\'], '', $downloadName);

        $this->streamBinaryFile(
            $fileInfo['path'],
            $downloadName,
            $fileInfo['mime_type'] ?: 'application/octet-stream',
            (int)$fileInfo['size']
        );
        exit;
    }

    /**
     * GET /api/drive/files/{id}/versions/{versionId}/preview
     * Returns base64 for binary formats, raw text for text files; the
     * compare UI decides how to render each type.
     */
    public function previewVersion(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');
        $versionId = $request->getParam('versionId');

        // "current" is a sentinel for the live content
        if ($versionId === 'current') {
            $file = $this->driveService->getFile($activeEmail, $fileId);
            if (!$file) {
                return Response::error('File not found', 404);
            }

            $path = $this->driveService->getFilePath($activeEmail, $fileId);
            if (!$path || !file_exists($path)) {
                return Response::error('File not found', 404);
            }

            $mimeType = $file['mime_type'];
            $size = $file['size'];
            $versionNumber = $file['current_version'] ?? 1;
            $originalFilename = $file['original_name'];
        } else {
            $fileInfo = $this->driveService->versioning()->getVersionFilePath($activeEmail, $fileId, (int)$versionId);

            if (!$fileInfo) {
                return Response::error('Version not found', 404);
            }

            $path = $fileInfo['path'];
            $mimeType = $fileInfo['mime_type'];
            $size = $fileInfo['size'];
            $versionNumber = $fileInfo['version_number'];
            $originalFilename = $fileInfo['filename'];
        }

        // Stored files may have no extension; use the original name for type detection
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        $isImage = strpos($mimeType, 'image/') === 0 || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']);
        $isPdf = $mimeType === 'application/pdf' || $ext === 'pdf';
        $isText = $this->isTextFile($mimeType, $path);
        $isDocx = in_array($mimeType, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword'
        ]) || in_array($ext, ['doc', 'docx']);
        $isSpreadsheet = in_array($mimeType, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv'
        ]) || in_array($ext, ['xls', 'xlsx', 'csv']);
        $isPpt = in_array($mimeType, [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint'
        ]) || in_array($ext, ['ppt', 'pptx']);

        $response = [
            'version_number' => $versionNumber,
            'mime_type' => $mimeType,
            'size' => $size,
            'type' => 'unknown'
        ];

        // Limit file size for preview (10MB max)
        if ($size > 10 * 1024 * 1024) {
            return Response::error('File too large for preview', 413);
        }

        set_time_limit(30);

        if ($isImage) {
            $response['type'] = 'image';
            $response['content'] = 'data:' . $mimeType . ';base64,' . base64_encode(file_get_contents($path));
        } elseif ($isPdf) {
            $response['type'] = 'pdf';
            $response['content'] = 'data:application/pdf;base64,' . base64_encode(file_get_contents($path));
        } elseif ($isDocx) {
            $response['type'] = 'docx';
            $response['content'] = 'data:' . $mimeType . ';base64,' . base64_encode(file_get_contents($path));
        } elseif ($isSpreadsheet) {
            $response['type'] = 'spreadsheet';
            $response['content'] = 'data:' . $mimeType . ';base64,' . base64_encode(file_get_contents($path));
        } elseif ($isPpt) {
            $response['type'] = 'ppt';
            $response['content'] = 'data:' . $mimeType . ';base64,' . base64_encode(file_get_contents($path));
        } elseif ($isText) {
            $response['type'] = 'text';
            $response['content'] = file_get_contents($path);
            $response['language'] = $this->detectLanguage($path, $mimeType);
        } else {
            $response['type'] = 'unsupported';
            $response['message'] = 'Preview not available for this file type';
        }

        return Response::success($response);
    }

    private function isTextFile(string $mimeType, string $path): bool
    {
        $textMimeTypes = [
            'text/plain', 'text/html', 'text/css', 'text/javascript',
            'text/xml', 'text/csv', 'text/markdown',
            'application/json', 'application/xml', 'application/javascript',
            'application/x-httpd-php', 'application/x-sh'
        ];

        if (in_array($mimeType, $textMimeTypes)) {
            return true;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $textExtensions = [
            'txt', 'md', 'json', 'xml', 'html', 'htm', 'css', 'js', 'ts',
            'php', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'hpp', 'cs',
            'go', 'rs', 'swift', 'kt', 'scala', 'sh', 'bash', 'zsh',
            'yaml', 'yml', 'toml', 'ini', 'conf', 'cfg', 'env',
            'sql', 'vue', 'jsx', 'tsx', 'svelte', 'astro'
        ];

        return in_array($ext, $textExtensions);
    }

    private function detectLanguage(string $path, string $mimeType): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $languageMap = [
            'js' => 'javascript', 'ts' => 'typescript', 'jsx' => 'jsx', 'tsx' => 'tsx',
            'php' => 'php', 'py' => 'python', 'rb' => 'ruby', 'java' => 'java',
            'c' => 'c', 'cpp' => 'cpp', 'h' => 'c', 'hpp' => 'cpp', 'cs' => 'csharp',
            'go' => 'go', 'rs' => 'rust', 'swift' => 'swift', 'kt' => 'kotlin',
            'scala' => 'scala', 'sh' => 'bash', 'bash' => 'bash', 'zsh' => 'bash',
            'html' => 'html', 'htm' => 'html', 'css' => 'css', 'scss' => 'scss',
            'json' => 'json', 'xml' => 'xml', 'yaml' => 'yaml', 'yml' => 'yaml',
            'md' => 'markdown', 'sql' => 'sql', 'vue' => 'vue', 'svelte' => 'svelte'
        ];

        return $languageMap[$ext] ?? 'plaintext';
    }
}

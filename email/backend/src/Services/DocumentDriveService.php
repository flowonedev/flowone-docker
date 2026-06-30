<?php

namespace Webmail\Services;

/**
 * Routes CRM Pro document uploads through the Drive system.
 * Resolves the correct subfolder inside the client's board folder based on document type,
 * then delegates the actual upload to DriveService.
 */
class DocumentDriveService
{
    private \PDO $db;
    private array $config;
    private DriveService $driveService;
    private string $userEmail;

    private const TYPE_FOLDER_MAP = [
        'contract'  => 'Signatures',
        'nda'       => 'Signatures',
        'agreement' => 'Signatures',
        'invoice'   => 'Invoices',
        'receipt'   => 'Invoices',
        'proposal'  => 'Proposals',
        'quote'     => 'Proposals',
        'other'     => 'Documents',
    ];

    private const IMAGE_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/svg+xml', 'image/bmp', 'image/avif',
    ];

    public function __construct(array $config, string $userEmail)
    {
        $this->config = $config;
        $this->userEmail = strtolower($userEmail);
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->driveService = new DriveService($config, $userEmail);
    }

    /**
     * Resolve the board folder for a given client.
     * Returns null if the client has no linked board.
     */
    public function getClientBoardFolder(int $clientId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT cb.board_id, b.name AS board_name
            FROM client_boards cb
            JOIN webmail_boards b ON b.id = cb.board_id
            WHERE cb.client_id = ?
            ORDER BY cb.linked_at ASC
            LIMIT 1
        ');
        $stmt->execute([$clientId]);
        $link = $stmt->fetch();

        if (!$link) {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT id, name, parent_id 
            FROM drive_folders 
            WHERE board_id = ? AND user_email = ? AND (is_trashed = 0 OR is_trashed IS NULL)
        ');
        $stmt->execute([$link['board_id'], $this->userEmail]);
        $folder = $stmt->fetch();

        if ($folder) {
            return $folder;
        }

        // Board exists but no Drive folder yet -- create it via the standard flow
        $boardsRoot = $this->driveService->findOrCreateFolder($this->userEmail, 'Boards');
        if (!$boardsRoot) {
            return null;
        }

        $cleanName = preg_replace('/[<>:"\/\\\\|?*]/', '_', $link['board_name']);
        $boardFolder = $this->driveService->createFolder(
            $this->userEmail,
            $cleanName,
            (int)$boardsRoot['id']
        );

        if ($boardFolder) {
            $this->db->prepare('UPDATE drive_folders SET board_id = ? WHERE id = ?')
                ->execute([$link['board_id'], $boardFolder['id']]);
        }

        return $boardFolder;
    }

    /**
     * Map a document_type + mime to the correct subfolder name.
     */
    public function resolveSubfolderName(string $documentType, string $mimeType): string
    {
        if (in_array($mimeType, self::IMAGE_MIMES, true)) {
            return 'Images';
        }

        return self::TYPE_FOLDER_MAP[$documentType] ?? 'Documents';
    }

    /**
     * Get or create the target subfolder for a document upload.
     * Path: Boards / {Board Name} / {Subfolder}
     *
     * @return array{folder: array, board_folder: array}|null
     */
    public function resolveUploadFolder(int $clientId, string $documentType, string $mimeType): ?array
    {
        $boardFolder = $this->getClientBoardFolder($clientId);
        if (!$boardFolder) {
            return null;
        }

        $subfolderName = $this->resolveSubfolderName($documentType, $mimeType);
        $subfolder = $this->driveService->findOrCreateFolder(
            $this->userEmail,
            $subfolderName,
            (int)$boardFolder['id']
        );

        if (!$subfolder) {
            return null;
        }

        return [
            'folder' => $subfolder,
            'board_folder' => $boardFolder,
        ];
    }

    /**
     * Upload a file to Drive inside the resolved client board subfolder.
     * Returns the Drive file record or null on failure.
     */
    public function uploadToClientDrive(int $clientId, string $documentType, array $uploadedFile): ?array
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($uploadedFile['tmp_name']) ?: 'application/octet-stream';

        $resolved = $this->resolveUploadFolder($clientId, $documentType, $mimeType);
        if (!$resolved) {
            return null;
        }

        try {
            return $this->driveService->uploadFile(
                $this->userEmail,
                $uploadedFile,
                (int)$resolved['folder']['id']
            );
        } catch (\RuntimeException $e) {
            error_log("DocumentDriveService: uploadFile failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload annotation attachment to Drive inside an Annotations subfolder.
     */
    public function uploadAnnotationAttachment(int $clientId, string $sourcePath, string $originalName): ?array
    {
        $boardFolder = $this->getClientBoardFolder($clientId);
        if (!$boardFolder) {
            return null;
        }

        $subfolder = $this->driveService->findOrCreateFolder(
            $this->userEmail,
            'Annotations',
            (int)$boardFolder['id']
        );

        if (!$subfolder) {
            return null;
        }

        return $this->driveService->uploadFromPath(
            $this->userEmail,
            $sourcePath,
            (int)$subfolder['id'],
            $originalName
        );
    }
}

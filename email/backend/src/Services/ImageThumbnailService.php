<?php
namespace Webmail\Services;

/**
 * ImageThumbnailService - Generates optimized thumbnails for mood board images.
 * 
 * Creates WebP (or JPEG fallback) thumbnails at configurable sizes.
 * Thumbnails are stored alongside originals in a /thumbs/ subdirectory.
 * 
 * Usage:
 *   $thumbService = new ImageThumbnailService();
 *   $thumbPath = $thumbService->generateThumbnail($sourcePath, $boardId);
 *   $thumbPath = $thumbService->generateFromDrive($driveService, $email, $driveFileId, $boardId);
 */
class ImageThumbnailService
{
    // Max dimensions for generated thumbnails (maintains aspect ratio)
    private const THUMB_MAX_WIDTH = 800;
    private const THUMB_MAX_HEIGHT = 800;
    
    // Quality settings
    private const WEBP_QUALITY = 80;
    private const JPEG_QUALITY = 82;
    
    // Supported input MIME types
    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg', 'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/tiff',
    ];
    
    private string $baseStoragePath;
    private bool $webpSupported;
    
    public function __construct(?string $baseStoragePath = null)
    {
        $this->baseStoragePath = $baseStoragePath ?: (realpath(__DIR__ . '/../../storage') ?: __DIR__ . '/../../storage');
        $this->webpSupported = function_exists('imagewebp');
    }
    
    /**
     * Generate a thumbnail from a local file path.
     * Returns the thumbnail's stored filename (relative to board's thumbs dir), or null on failure.
     */
    public function generateThumbnail(string $sourcePath, int $boardId, ?string $storedFilename = null): ?string
    {
        if (!file_exists($sourcePath)) {
            error_log("[ImageThumbnailService] Source file not found: {$sourcePath}");
            return null;
        }
        
        // Check mime type
        $mimeType = @mime_content_type($sourcePath);
        if (!$mimeType || !in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) {
            // Not an image we can thumbnail — skip silently
            return null;
        }
        
        // Get original dimensions
        $imgInfo = @getimagesize($sourcePath);
        if (!$imgInfo) {
            error_log("[ImageThumbnailService] Cannot read image info: {$sourcePath}");
            return null;
        }
        
        $origWidth = $imgInfo[0];
        $origHeight = $imgInfo[1];
        
        // Skip if already small enough (no point making a thumbnail of a small image)
        if ($origWidth <= self::THUMB_MAX_WIDTH && $origHeight <= self::THUMB_MAX_HEIGHT) {
            return null; // null = "use original, it's already small"
        }
        
        // Calculate target dimensions (maintain aspect ratio)
        $ratio = min(self::THUMB_MAX_WIDTH / $origWidth, self::THUMB_MAX_HEIGHT / $origHeight);
        $newWidth = (int)round($origWidth * $ratio);
        $newHeight = (int)round($origHeight * $ratio);
        
        // Create GD image from source
        $srcImage = $this->createImageFromFile($sourcePath, $mimeType);
        if (!$srcImage) {
            error_log("[ImageThumbnailService] Failed to create GD image from: {$sourcePath}");
            return null;
        }
        
        // Create resized image
        $thumbImage = imagecreatetruecolor($newWidth, $newHeight);
        if (!$thumbImage) {
            imagedestroy($srcImage);
            return null;
        }
        
        // Preserve transparency for PNG/WebP
        if (in_array($mimeType, ['image/png', 'image/webp'])) {
            imagealphablending($thumbImage, false);
            imagesavealpha($thumbImage, true);
            $transparent = imagecolorallocatealpha($thumbImage, 0, 0, 0, 127);
            imagefill($thumbImage, 0, 0, $transparent);
        }
        
        // Resize with high quality
        imagecopyresampled($thumbImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        imagedestroy($srcImage);
        
        // Ensure thumbs directory exists
        $thumbsDir = $this->getThumbsDir($boardId);
        if (!is_dir($thumbsDir)) {
            if (!mkdir($thumbsDir, 0755, true)) {
                error_log("[ImageThumbnailService] Failed to create thumbs dir: {$thumbsDir}");
                imagedestroy($thumbImage);
                return null;
            }
        }
        
        // Generate output filename
        $baseName = $storedFilename ?: basename($sourcePath);
        $nameWithoutExt = pathinfo($baseName, PATHINFO_FILENAME);
        
        // Save as WebP if supported, otherwise JPEG
        if ($this->webpSupported) {
            $thumbFilename = $nameWithoutExt . '.thumb.webp';
            $thumbPath = $thumbsDir . '/' . $thumbFilename;
            $success = imagewebp($thumbImage, $thumbPath, self::WEBP_QUALITY);
        } else {
            $thumbFilename = $nameWithoutExt . '.thumb.jpg';
            $thumbPath = $thumbsDir . '/' . $thumbFilename;
            $success = imagejpeg($thumbImage, $thumbPath, self::JPEG_QUALITY);
        }
        
        imagedestroy($thumbImage);
        
        if (!$success) {
            error_log("[ImageThumbnailService] Failed to write thumbnail: {$thumbPath}");
            return null;
        }
        
        return $thumbFilename;
    }
    
    /**
     * Generate thumbnail for a Drive-stored file.
     * Reads the file from Drive, creates thumbnail, stores locally in thumbs dir.
     */
    public function generateFromDrive(DriveService $driveService, string $email, int $driveFileId, int $boardId, ?string $storedFilename = null): ?string
    {
        try {
            $filePath = $driveService->getFilePath($email, $driveFileId);
            if (!$filePath || !file_exists($filePath)) {
                return null;
            }
            return $this->generateThumbnail($filePath, $boardId, $storedFilename);
        } catch (\Exception $e) {
            error_log("[ImageThumbnailService] Drive thumbnail error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Batch generate thumbnails for all uploads of a board.
     * Returns count of thumbnails generated.
     */
    public function generateBoardThumbnails(\PDO $db, int $boardId, array $config): array
    {
        $generated = 0;
        $skipped = 0;
        $failed = 0;
        
        try {
            $stmt = $db->prepare("
                SELECT id, board_id, stored_filename, file_path, mime_type, drive_file_id, uploaded_by, thumbnail_filename
                FROM mood_board_uploads 
                WHERE board_id = ?
            ");
            $stmt->execute([$boardId]);
            $uploads = $stmt->fetchAll();
            
            foreach ($uploads as $upload) {
                // Skip if already has a thumbnail
                if (!empty($upload['thumbnail_filename'])) {
                    $thumbPath = $this->getThumbsDir($boardId) . '/' . $upload['thumbnail_filename'];
                    if (file_exists($thumbPath)) {
                        $skipped++;
                        continue;
                    }
                }
                
                // Skip non-image files
                $mime = $upload['mime_type'] ?? '';
                if (!str_starts_with($mime, 'image/') || !in_array($mime, self::SUPPORTED_MIME_TYPES, true)) {
                    $skipped++;
                    continue;
                }
                
                $thumbFilename = null;
                
                // Try local file first
                $localPath = $this->baseStoragePath . '/mood-uploads/' . $boardId . '/' . $upload['stored_filename'];
                if (file_exists($localPath)) {
                    $thumbFilename = $this->generateThumbnail($localPath, $boardId, $upload['stored_filename']);
                } elseif (!empty($upload['drive_file_id'])) {
                    // Try Drive storage
                    try {
                        $uploaderEmail = $upload['uploaded_by'] ?? '';
                        if ($uploaderEmail) {
                            $driveService = new DriveService($config, $uploaderEmail);
                            $thumbFilename = $this->generateFromDrive($driveService, $uploaderEmail, (int)$upload['drive_file_id'], $boardId, $upload['stored_filename']);
                        }
                    } catch (\Exception $e) {
                        error_log("[ImageThumbnailService] Drive thumb gen failed for upload #{$upload['id']}: " . $e->getMessage());
                    }
                }
                
                if ($thumbFilename) {
                    // Store thumbnail filename in DB
                    $updateStmt = $db->prepare("UPDATE mood_board_uploads SET thumbnail_filename = ? WHERE id = ?");
                    $updateStmt->execute([$thumbFilename, $upload['id']]);
                    $generated++;
                } elseif ($thumbFilename === null && str_starts_with($mime, 'image/')) {
                    // null means original is small enough or unsupported — mark with special value
                    $updateStmt = $db->prepare("UPDATE mood_board_uploads SET thumbnail_filename = '__original__' WHERE id = ?");
                    $updateStmt->execute([$upload['id']]);
                    $skipped++;
                } else {
                    $failed++;
                }
            }
        } catch (\Exception $e) {
            error_log("[ImageThumbnailService] Batch generation error: " . $e->getMessage());
        }
        
        return [
            'generated' => $generated,
            'skipped' => $skipped,
            'failed' => $failed,
            'total' => $generated + $skipped + $failed,
        ];
    }
    
    /**
     * Get the thumbnail URL for a given upload record.
     * Returns null if no thumbnail available (use original).
     */
    public function getThumbnailUrl(int $boardId, ?string $thumbnailFilename, string $storedFilename): ?string
    {
        if (empty($thumbnailFilename) || $thumbnailFilename === '__original__') {
            return null; // Use original
        }
        
        $thumbPath = $this->getThumbsDir($boardId) . '/' . $thumbnailFilename;
        if (file_exists($thumbPath)) {
            return '/api/mood-boards/' . $boardId . '/uploads/thumbs/' . $thumbnailFilename;
        }
        
        return null;
    }
    
    /**
     * Serve a thumbnail file. Returns false if not found.
     */
    public function serveThumbnail(int $boardId, string $thumbFilename): bool
    {
        $thumbPath = $this->getThumbsDir($boardId) . '/' . $thumbFilename;
        
        if (!file_exists($thumbPath)) {
            return false;
        }
        
        // Determine mime type
        $ext = strtolower(pathinfo($thumbFilename, PATHINFO_EXTENSION));
        $mimeType = match ($ext) {
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => mime_content_type($thumbPath) ?: 'application/octet-stream',
        };
        
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($thumbPath));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: "' . md5($boardId . '/' . $thumbFilename) . '"');
        
        readfile($thumbPath);
        exit;
    }
    
    /**
     * Get the thumbs directory path for a board.
     */
    public function getThumbsDir(int $boardId): string
    {
        return $this->baseStoragePath . '/mood-uploads/' . $boardId . '/thumbs';
    }
    
    /**
     * Create a GD image resource from a file.
     */
    private function createImageFromFile(string $path, string $mimeType): ?\GdImage
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path) ?: null,
            'image/png' => @imagecreatefrompng($path) ?: null,
            'image/gif' => @imagecreatefromgif($path) ?: null,
            'image/webp' => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null,
            'image/bmp' => function_exists('imagecreatefrombmp') ? (@imagecreatefrombmp($path) ?: null) : null,
            default => null,
        };
    }
}


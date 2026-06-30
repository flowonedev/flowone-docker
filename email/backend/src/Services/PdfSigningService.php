<?php

namespace Webmail\Services;

use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * PdfSigningService - Overlays signature and stamp images onto PDF documents
 * at designated zone coordinates.
 *
 * Uses FPDI to import existing PDF pages and TCPDF to place images.
 * All zone coordinates are stored as percentages and converted to PDF points
 * (1 point = 1/72 inch) at render time.
 *
 * Supports chain-signing: each signer's output becomes the next signer's input.
 */
class PdfSigningService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Overlay signature and/or stamp images onto a PDF at zone positions.
     *
     * @param string $pdfPath Path to the source PDF
     * @param array $zones Array of zone records from portal_document_zones for THIS signer
     * @param array $signerData ['signature_data' => base64, 'stamp_data' => base64, 'name' => '', 'email' => '', 'signed_at' => '', 'ip' => '']
     * @param string $outputPath Where to save the resulting PDF
     * @return string The output file path
     */
    public function overlaySignatures(string $pdfPath, array $zones, array $signerData, string $outputPath): string
    {
        if (!file_exists($pdfPath)) {
            throw new \RuntimeException('Source PDF not found: ' . $pdfPath);
        }

        $pdf = new Fpdi('P', 'pt');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0);

        $pageCount = $pdf->setSourceFile($pdfPath);

        // Index zones by page for quick lookup
        $zonesByPage = [];
        foreach ($zones as $zone) {
            $pg = (int)($zone['page_number'] ?? 1);
            $zonesByPage[$pg][] = $zone;
        }

        // Decode signature and stamp images to temp files
        $signatureTmp = $this->decodeBase64ToTempFile($signerData['signature_data'] ?? null, 'sig');
        $stampTmp = $this->decodeBase64ToTempFile($signerData['stamp_data'] ?? null, 'stamp');

        try {
            for ($pageNum = 1; $pageNum <= $pageCount; $pageNum++) {
                $tplId = $pdf->importPage($pageNum);
                $size = $pdf->getTemplateSize($tplId);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height']);

                $pageWidth = $size['width'];
                $pageHeight = $size['height'];

                if (!isset($zonesByPage[$pageNum])) continue;

                foreach ($zonesByPage[$pageNum] as $zone) {
                    $x = ($zone['x_percent'] / 100) * $pageWidth;
                    $y = ($zone['y_percent'] / 100) * $pageHeight;
                    $w = ($zone['width_percent'] / 100) * $pageWidth;
                    $h = ($zone['height_percent'] / 100) * $pageHeight;

                    $zoneType = $zone['zone_type'] ?? 'signature';

                    if ($zoneType === 'signature' || $zoneType === 'signature_and_stamp') {
                        if ($signatureTmp) {
                            $imgH = ($zoneType === 'signature_and_stamp') ? $h * 0.55 : $h * 0.7;
                            $this->placeImageFit($pdf, $signatureTmp, $x, $y, $w, $imgH);
                        }
                    }

                    if ($zoneType === 'stamp' || $zoneType === 'signature_and_stamp') {
                        if ($stampTmp) {
                            $stampY = ($zoneType === 'signature_and_stamp') ? $y + ($h * 0.05) : $y;
                            $stampX = ($zoneType === 'signature_and_stamp') ? $x + ($w * 0.55) : $x;
                            $stampW = ($zoneType === 'signature_and_stamp') ? $w * 0.4 : $w;
                            $stampH = ($zoneType === 'signature_and_stamp') ? $h * 0.55 : $h * 0.7;
                            $this->placeImageFit($pdf, $stampTmp, $stampX, $stampY, $stampW, $stampH);
                        }
                    }

                    // Metadata text at bottom of zone
                    $metaY = $y + $h - 8;
                    $metaText = ($signerData['name'] ?? $signerData['email'] ?? 'Signer');
                    $metaText .= ' | ' . ($signerData['signed_at'] ?? date('Y-m-d H:i'));
                    if (!empty($signerData['ip'])) {
                        $metaText .= ' | IP: ' . $signerData['ip'];
                    }

                    $pdf->SetFont('helvetica', '', 5);
                    $pdf->SetTextColor(100, 100, 100);
                    $pdf->SetXY($x, $metaY);
                    $pdf->Cell($w, 7, $metaText, 0, 0, 'L', false, '', 0, false, 'T', 'M');
                }
            }

            $outputDir = dirname($outputPath);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $pdf->Output($outputPath, 'F');
        } finally {
            if ($signatureTmp && file_exists($signatureTmp)) @unlink($signatureTmp);
            if ($stampTmp && file_exists($stampTmp)) @unlink($stampTmp);
        }

        return $outputPath;
    }

    /**
     * Get the current base PDF for signing (handles chain-signing).
     * If a previous signer already signed, returns the latest signed version.
     * Otherwise returns the original document path.
     */
    public function getBasePdfPath(\PDO $db, int $docId): string
    {
        $stmt = $db->prepare('SELECT file_path, signed_file_path FROM portal_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();

        if (!$doc) {
            throw new \RuntimeException('Document not found: ' . $docId);
        }

        // Use the latest signed version if it exists (chain-signing)
        if ($doc['signed_file_path'] && file_exists($doc['signed_file_path'])) {
            return $doc['signed_file_path'];
        }

        // Fall back to original
        if (file_exists($doc['file_path'])) {
            return $doc['file_path'];
        }

        throw new \RuntimeException('PDF file not found on disk for document: ' . $docId);
    }

    /**
     * Generate the output path for a signed PDF version.
     */
    public function generateSignedPath(int $clientId, int $docId, int $signerId): string
    {
        $basePath = ($this->config['storage_path'] ?? dirname(__DIR__, 2) . '/storage');
        $dir = $basePath . '/portal/' . $clientId . '/documents/signed/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return $dir . 'signed_' . $docId . '_v' . $signerId . '_' . time() . '.pdf';
    }

    /**
     * Place an image inside a bounding box, maintaining aspect ratio.
     */
    private function placeImageFit(Fpdi $pdf, string $imagePath, float $x, float $y, float $maxW, float $maxH): void
    {
        $imageSize = @getimagesize($imagePath);
        if (!$imageSize) return;

        $imgW = $imageSize[0];
        $imgH = $imageSize[1];
        $ratio = min($maxW / $imgW, $maxH / $imgH);

        $drawW = $imgW * $ratio;
        $drawH = $imgH * $ratio;

        $drawX = $x + ($maxW - $drawW) / 2;
        $drawY = $y + ($maxH - $drawH) / 2;

        $pdf->Image($imagePath, $drawX, $drawY, $drawW, $drawH, '', '', '', false, 300);
    }

    /**
     * Decode a base64 data URI to a temporary PNG file.
     * Returns null if input is empty/invalid.
     */
    private function decodeBase64ToTempFile(?string $base64, string $prefix = 'img'): ?string
    {
        if (!$base64) return null;

        // Strip data URI prefix
        if (str_contains($base64, ',')) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        $data = base64_decode($base64, true);
        if (!$data || strlen($data) < 8) return null;

        $tmp = tempnam(sys_get_temp_dir(), 'pdfsign_' . $prefix . '_');
        $pngPath = $tmp . '.png';
        file_put_contents($pngPath, $data);

        if (file_exists($tmp)) @unlink($tmp);

        return $pngPath;
    }
}

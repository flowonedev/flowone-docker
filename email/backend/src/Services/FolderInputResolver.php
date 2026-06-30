<?php

namespace Webmail\Services;

/**
 * FolderInputResolver - bridges legacy path-shaped folder URLs and the
 * canonical folder_id-shaped URLs introduced in Wave 2 P2.
 *
 * Wave 2 P2 contract:
 *
 *   The HTTP API exposes two shapes for every folder-scoped endpoint:
 *
 *     Legacy:    /mailbox/{folder}/messages          (folder = IMAP path)
 *     Canonical: /folders/{folder_id}/messages       (folder_id = UUIDv7)
 *
 *   Both shapes map to the same controller method. The method calls
 *   `BaseController::getResolvedFolder()` which delegates here, and the
 *   resolver returns BOTH the folder_id and the legacy path. The
 *   controller continues to use the legacy `$folder` path internally
 *   (because every IMAP call still operates on paths), while telemetry
 *   counts which URL shape the client used. Once `legacy_route_hits_24h`
 *   stays at zero for 7 consecutive days, the legacy routes can be
 *   dropped (P2 cutover).
 *
 *   The resolver auto-detects which shape was supplied:
 *     - Input matching the UUIDv7 pattern -> treat as folder_id.
 *     - Anything else -> treat as a folder path (legacy).
 *
 *   When a folder_id is supplied but the identity row has been deleted
 *   (state='deleted' or row is gone), returns null path so the caller
 *   can return 404.
 *
 *   When a folder path is supplied but no identity row exists yet
 *   (e.g. a fresh account that hasn't visited /mailbox/folders since
 *   Wave 2 shipped), returns null folder_id but valid folder path so
 *   legacy IMAP calls keep working. The first /mailbox/folders refresh
 *   will register the folder and subsequent requests will resolve it.
 */
final class FolderInputResolver
{
    /**
     * Strict UUIDv7 pattern (RFC 9562). Version nibble is `7`, variant
     * nibble is one of {8, 9, a, b}. We use this to disambiguate "is the
     * incoming string a folder_id or a folder path".
     */
    private const UUIDV7_REGEX =
        '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private array $config;
    private ?FolderIndexService $folderIndex = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Decide whether the given input looks like a UUIDv7. Reserved as a
     * helper so route-level code can decide which URL shape to register
     * without duplicating the regex.
     */
    public static function looksLikeFolderId(string $input): bool
    {
        return (bool) preg_match(self::UUIDV7_REGEX, trim($input));
    }

    /**
     * Resolve `$input` against the active account's identity table.
     *
     * @param string $accountId
     * @param string|null $input either a UUIDv7 folder_id, an IMAP folder
     *   path, or null/empty (which returns a triple of nulls).
     *
     * @return array{folder_id: ?string, folder_path: ?string, source: string}
     *   `source` is one of:
     *     - 'folder_id'   -- input matched the UUIDv7 regex; folder_id
     *                        was authoritative.
     *     - 'path'        -- input was treated as a folder path; the
     *                        identity table provided folder_id (or null
     *                        when the folder isn't tracked yet).
     *     - 'unresolved'  -- input was empty/null.
     */
    public function resolve(string $accountId, ?string $input): array
    {
        $trimmed = is_string($input) ? trim($input) : '';
        if ($trimmed === '') {
            return [
                'folder_id'   => null,
                'folder_path' => null,
                'source'      => 'unresolved',
            ];
        }

        if (self::looksLikeFolderId($trimmed)) {
            return $this->resolveByFolderId($accountId, $trimmed);
        }

        return $this->resolveByPath($accountId, $trimmed);
    }

    private function resolveByFolderId(string $accountId, string $folderId): array
    {
        $svc = $this->getFolderIndex();
        if ($svc === null) {
            return [
                'folder_id'   => $folderId,
                'folder_path' => null,
                'source'      => 'folder_id',
            ];
        }
        $row = $svc->getById($folderId);
        if ($row === null) {
            return [
                'folder_id'   => $folderId,
                'folder_path' => null,
                'source'      => 'folder_id',
            ];
        }
        // Belt and braces: do not let a different account's folder_id
        // resolve under the active account, even if a malicious caller
        // guesses a UUID.
        if (!hash_equals(strtolower((string) ($row['account_id'] ?? '')), strtolower($accountId))) {
            return [
                'folder_id'   => $folderId,
                'folder_path' => null,
                'source'      => 'folder_id',
            ];
        }
        return [
            'folder_id'   => (string) $row['id'],
            'folder_path' => (string) $row['current_path'],
            'source'      => 'folder_id',
        ];
    }

    private function resolveByPath(string $accountId, string $path): array
    {
        $svc = $this->getFolderIndex();
        if ($svc === null) {
            return [
                'folder_id'   => null,
                'folder_path' => $path,
                'source'      => 'path',
            ];
        }
        $row = $svc->getByPath($accountId, $path);
        if ($row === null) {
            // Folder isn't yet tracked in the identity table; this is
            // expected for accounts that haven't refreshed since Wave-2
            // shipped. Legacy routes still work because the IMAP layer
            // doesn't need folder_id.
            return [
                'folder_id'   => null,
                'folder_path' => $path,
                'source'      => 'path',
            ];
        }
        return [
            'folder_id'   => (string) ($row['id'] ?? null),
            'folder_path' => (string) ($row['current_path'] ?? $path),
            'source'      => 'path',
        ];
    }

    private function getFolderIndex(): ?FolderIndexService
    {
        if ($this->folderIndex === null) {
            try {
                $this->folderIndex = new FolderIndexService($this->config);
            } catch (\Throwable $e) {
                error_log('[FolderInputResolver] FolderIndexService init failed: ' . $e->getMessage());
                return null;
            }
        }
        return $this->folderIndex;
    }

    /**
     * Wave 2 P2 Track #3: compare-mode telemetry.
     *
     * Resolves the SAME logical folder via BOTH lookup paths and reports
     * divergence. Used to verify there is no silent identity drift between
     * `legacy URL -> path -> identity` and `canonical URL -> folder_id ->
     * identity` before we drop the legacy code paths at cutover.
     *
     * Inputs are the resolved pair from the active request: at least ONE
     * of `$folderId` / `$folderPath` is required (we cannot diff a single
     * unknown). The method runs the OTHER lookup and returns:
     *
     *   - 'ok'              both lookups agree: same folder_id, same path
     *   - 'identity_drift'  the round-trip points at a different identity
     *                       (the bug we MUST catch before cutover)
     *   - 'partial'         one lookup found nothing (typically: identity
     *                       exists but interval row missing -- recoverable,
     *                       but worth seeing in the dashboard)
     *   - 'skipped'         input was insufficient for a useful comparison
     *
     * The return shape is suitable for emitting to StructuredLog without
     * further massaging.
     *
     * Side-effect free. Read-only.
     *
     * @return array{
     *     status: string,
     *     by_id?: array{folder_id: ?string, folder_path: ?string},
     *     by_path?: array{folder_id: ?string, folder_path: ?string},
     *     details?: string,
     * }
     */
    public function compareResolve(
        string $accountId,
        ?string $folderId,
        ?string $folderPath
    ): array {
        $hasId = is_string($folderId) && $folderId !== '';
        $hasPath = is_string($folderPath) && $folderPath !== '';
        if (!$hasId || !$hasPath) {
            // Comparison only makes sense when we already have both forms.
            // The resolver populates the missing side from a single input;
            // if it couldn't, there's nothing to diff against.
            return [
                'status'  => 'skipped',
                'details' => 'compare requires both folder_id and folder_path',
            ];
        }

        $byId = $this->resolveByFolderId($accountId, $folderId);
        $byPath = $this->resolveByPath($accountId, $folderPath);

        $idGotPath = $byId['folder_path'];
        $pathGotId = $byPath['folder_id'];

        // Partial: one of the two lookups returned nothing. Common cause:
        // identity row exists but the open interval is missing for the
        // current path. Recoverable via `backfill-folder-ids.php
        // --repair-intervals`; report so we can see how often it happens.
        if ($idGotPath === null || $pathGotId === null) {
            return [
                'status'  => 'partial',
                'by_id'   => [
                    'folder_id'   => $byId['folder_id'],
                    'folder_path' => $idGotPath,
                ],
                'by_path' => [
                    'folder_id'   => $pathGotId,
                    'folder_path' => $byPath['folder_path'],
                ],
                'details' => $idGotPath === null
                    ? 'folder_id resolves but path lookup empty'
                    : 'path resolves but folder_id lookup empty',
            ];
        }

        // Drift: both sides found something, but they disagree on which
        // identity row is canonical for this (account, path) pair. THIS
        // is the silent bug that would survive cutover unnoticed.
        $idMismatch = !hash_equals(
            strtolower((string) $byId['folder_id']),
            strtolower((string) $pathGotId)
        );
        $pathMismatch = strcasecmp((string) $idGotPath, (string) $byPath['folder_path']) !== 0;
        if ($idMismatch || $pathMismatch) {
            return [
                'status'  => 'identity_drift',
                'by_id'   => [
                    'folder_id'   => $byId['folder_id'],
                    'folder_path' => $idGotPath,
                ],
                'by_path' => [
                    'folder_id'   => $pathGotId,
                    'folder_path' => $byPath['folder_path'],
                ],
                'details' => $idMismatch && $pathMismatch
                    ? 'both folder_id and folder_path disagree'
                    : ($idMismatch
                        ? 'folder_id mismatch (path lookup yields a different id)'
                        : 'folder_path mismatch (folder_id current_path differs from input)'),
            ];
        }

        return ['status' => 'ok'];
    }
}

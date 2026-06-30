<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Support;

/**
 * Single source of truth for deriving Linux / MySQL resource names
 * from a site's domain.
 *
 * Why this exists:
 *   - Multiple saga steps (SftpGroupCreate, SftpUserCreate,
 *     SftpGroupRemove, SftpUserRemove, DatabaseCreate,
 *     DatabaseUserCreate, etc.) need to agree on what user/group/db
 *     name corresponds to a given domain.
 *   - The pre-existing arrangement was each step inlining its own
 *     copy of the same algorithm, with "MUST match byte-for-byte"
 *     comments and no enforcement. Predictably, the CREATE saga
 *     regressed in production: SftpGroupCreateStep made the group,
 *     SftpUserCreateStep was supposed to find it via the saga's
 *     cross-step state JSON (which isn't hydrated into SiteContext
 *     today), and when that lookup failed there was no fall-through
 *     so the saga threw "cannot resolve primary group".
 *   - With the algorithm centralised here, every step trivially
 *     converges on the same name, no cross-step state plumbing
 *     required, and updates to the algorithm propagate everywhere
 *     at once.
 *
 * Algorithms (each chosen for the platform's identifier limits):
 *
 *   sftpName():    "site_<sanitized>"     -> Linux user/group, 31-char cap
 *                  (Linux NAME_MAX for user/group names)
 *   dbName():      "flowone_<sanitized>"  -> MySQL DB, 64-char cap
 *                  (MariaDB identifier limit)
 *   dbUser():      "fo_<sanitized>"       -> MySQL user, 32-char cap
 *                  (MariaDB 10.x user limit; 11.x allows 80 but cap
 *                  at 32 for portability)
 *
 * "Sanitized" = lowercase, every non-[a-z0-9_] replaced with '_',
 * trimmed of leading/trailing '_'. This is intentionally lossy:
 * "foo.bar.com" and "foo-bar-com" both collapse to the same
 * sanitized form ("foo_bar_com"). The hash suffix on overflow
 * keeps even pathologically long domains disambiguated.
 *
 * Overflow rule: when "<prefix>_<sanitized>" exceeds the limit,
 * we keep the first (limit - 7) chars and append "_" plus the
 * first 6 hex chars of sha1(original) so name collisions are
 * astronomically unlikely while staying deterministic.
 */
final class ResourceNameDeriver
{
    /** Linux NAME_MAX for users and groups (utmp constraint). */
    public const SFTP_LIMIT = 31;
    /** MariaDB identifier max (database name). */
    public const DB_NAME_LIMIT = 64;
    /** Portable upper bound for MariaDB user names (10.x compatible). */
    public const DB_USER_LIMIT = 32;

    /**
     * Linux user / group name for a site's SFTP account.
     * The same name is used for both the user and its primary group.
     *
     * @throws \RuntimeException if the domain has no usable characters.
     */
    public static function sftpName(string $domain): string
    {
        return self::derive($domain, 'site_', self::SFTP_LIMIT, 'sftp name');
    }

    /**
     * MariaDB database name for a site's primary DB.
     */
    public static function dbName(string $domain): string
    {
        return self::derive($domain, 'flowone_', self::DB_NAME_LIMIT, 'db name');
    }

    /**
     * MariaDB user name for a site's primary DB user.
     */
    public static function dbUser(string $domain): string
    {
        return self::derive($domain, 'fo_', self::DB_USER_LIMIT, 'db user');
    }

    /**
     * Shared derivation engine. Kept private so the only entry
     * points are the three prefix-specific methods above; that
     * stops "creative" callers from inventing new prefixes
     * ad-hoc and bypassing the convention.
     *
     * @param string $domain       caller's domain (raw, may have dots / dashes)
     * @param string $prefix       the typed prefix ("site_", "flowone_", "fo_")
     * @param int    $limit        platform identifier limit
     * @param string $kindForError human label used in the error message
     */
    private static function derive(string $domain, string $prefix, int $limit, string $kindForError): string
    {
        $sanitized = strtolower($domain);
        $sanitized = preg_replace('/[^a-z0-9_]/', '_', $sanitized) ?? '';
        $sanitized = trim($sanitized, '_');
        if ($sanitized === '') {
            throw new \RuntimeException(
                "Cannot derive {$kindForError} from empty/odd domain: '{$domain}'"
            );
        }
        $candidate = $prefix . $sanitized;
        if (strlen($candidate) > $limit) {
            // Reserve 7 chars for "_<6 hex>". Trim any trailing
            // underscore on the truncated prefix so we don't end
            // up with "..__abcdef" which looks like a bug.
            $hash = substr(hash('sha1', $candidate), 0, 6);
            $head = rtrim(substr($candidate, 0, $limit - 7), '_');
            $candidate = $head . '_' . $hash;
        }
        return $candidate;
    }
}

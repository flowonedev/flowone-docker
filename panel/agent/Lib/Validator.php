<?php
/**
 * Input Validator
 * 
 * Validates and sanitizes all inputs to the agent.
 * Prevents injection attacks and ensures data integrity.
 */

namespace VpsAdmin\Agent\Lib;

class Validator
{
    /**
     * Validate a public FQDN (one that could plausibly resolve on the
     * public internet). Requires at least one label-plus-dot suffix
     * with a 2+ alpha TLD: e.g. `example.com`, `mail.foo.co.uk`.
     *
     * Use this when the caller will issue against DNS, Let's Encrypt,
     * SMTP delivery, or anything else that assumes a real public name.
     * For path-safety / vhost-config-lookup, prefer hostname() below.
     */
    public static function domain(string $domain): bool
    {
        // Must be valid domain format
        if (!preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domain)) {
            return false;
        }

        // No path traversal
        if (strpos($domain, '..') !== false || strpos($domain, '/') !== false) {
            return false;
        }

        return strlen($domain) <= 253;
    }

    /**
     * Validate a hostname per RFC 1123 §2.1: accepts both
     * single-label hosts (e.g. `test6`, `wiki`, `staging`) AND
     * dotted FQDNs (e.g. `example.com`).
     *
     * Use this when the validator is guarding a path-component lookup
     * (vhost.conf for a site, home dir for a user, etc.) and we don't
     * actually need the value to resolve over public DNS. The new
     * provisioning saga creates single-label sites freely for QA;
     * legacy paths that pivot on Validator::domain() reject them and
     * cause inconsistencies (list shows the site, detail says "not
     * found" - the bug Job #559 surfaced).
     *
     * Rules:
     *   - 1..253 chars total
     *   - Each label 1..63 chars
     *   - Labels: [a-zA-Z0-9] start/end, [a-zA-Z0-9-] interior
     *   - No leading/trailing dot, no double dots
     *   - No path-traversal sequences (`..`, `/`, `\`)
     *   - No ASCII whitespace or control chars
     */
    public static function hostname(string $name): bool
    {
        if ($name === '' || strlen($name) > 253) {
            return false;
        }
        if (strpos($name, '..') !== false
            || strpos($name, '/') !== false
            || strpos($name, '\\') !== false
        ) {
            return false;
        }
        // Reject leading/trailing dot
        if ($name[0] === '.' || substr($name, -1) === '.') {
            return false;
        }
        // Single-label or multi-label, each label valid per RFC 1123.
        $labelRe = '[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?';
        return preg_match('/^' . $labelRe . '(?:\.' . $labelRe . ')*$/', $name) === 1;
    }

    /**
     * Validate a service name
     */
    public static function serviceName(string $name, array $allowedServices): bool
    {
        return in_array($name, $allowedServices, true);
    }

    /**
     * Validate a database name
     */
    public static function databaseName(string $name): bool
    {
        // MySQL database name rules
        return preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $name) === 1;
    }

    /**
     * Validate a username
     */
    public static function username(string $name): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/', $name) === 1;
    }

    /**
     * Validate an email address
     */
    public static function email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate an IP address (v4 or v6)
     */
    public static function ipAddress(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate an IPv4 address
     */
    public static function ipv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Validate an IPv6 address
     */
    public static function ipv6(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Validate a port number
     */
    public static function port(int $port): bool
    {
        return $port >= 1 && $port <= 65535;
    }

    /**
     * Validate a file path (no traversal)
     */
    public static function safePath(string $path, string $basePath): bool
    {
        $realBase = realpath($basePath);
        if ($realBase === false) {
            return false;
        }

        // Resolve the full path
        $fullPath = $basePath . '/' . $path;
        $realPath = realpath(dirname($fullPath));
        
        if ($realPath === false) {
            // Directory doesn't exist yet, check for traversal in path
            if (strpos($path, '..') !== false) {
                return false;
            }
            return true;
        }

        // Ensure path is within base
        return strpos($realPath, $realBase) === 0;
    }

    /**
     * Validate a MySQL identifier (database name, username) for safe DDL interpolation.
     * Rejects anything that isn't pure alphanumeric + underscore.
     */
    public static function dbIdentifier(string $value): bool
    {
        return preg_match('/^[a-zA-Z0-9_]{1,64}$/', $value) === 1;
    }

    /**
     * Validate a MySQL host value for DDL interpolation.
     */
    public static function dbHost(string $host): bool
    {
        if ($host === 'localhost' || $host === '%') {
            return true;
        }
        // Allow IP addresses and hostnames
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }
        return preg_match('/^[a-zA-Z0-9._-]{1,255}$/', $host) === 1;
    }

    /**
     * Validate a WordPress table prefix for safe SQL interpolation.
     */
    public static function wpPrefix(string $prefix): bool
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,31}$/', $prefix) === 1;
    }

    /**
     * Validate a Fail2ban jail name
     */
    public static function jailName(string $name): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $name) === 1;
    }

    /**
     * Validate a firewall zone name
     */
    public static function zoneName(string $name): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/', $name) === 1;
    }

    /**
     * Validate DNS record type
     */
    public static function dnsRecordType(string $type): bool
    {
        $validTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SOA', 'PTR', 'SRV', 'CAA'];
        return in_array(strtoupper($type), $validTypes, true);
    }

    /**
     * Validate a positive integer
     */
    public static function positiveInt($value): bool
    {
        return is_numeric($value) && (int)$value > 0;
    }

    /**
     * Sanitize a string for safe file naming
     */
    public static function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    }

    /**
     * Validate ModSecurity mode
     */
    public static function modsecMode(string $mode): bool
    {
        return in_array($mode, ['On', 'Off', 'DetectionOnly'], true);
    }
}


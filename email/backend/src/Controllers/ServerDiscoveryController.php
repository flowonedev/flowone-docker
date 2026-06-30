<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Core\Database;

/**
 * ServerDiscoveryController
 * ---------------------------------------------------------------
 * Tells the native apps (iOS / Android / Drive) WHICH backend hosts a
 * given email domain, so a single app binary can serve both:
 *
 *   1. The shared host (flowone.pro), where one server holds mailboxes for
 *      many domains (pixelranger.hu, kiskonyvecske, ...). Those users log in
 *      against THIS server even though their email domain is not flowone.pro.
 *
 *   2. Dedicated per-customer deployments that follow the deploy convention
 *      email.<domain> (e.g. robert@magyarszinhaz.hu -> email.magyarszinhaz.hu).
 *
 * The app cannot tell these apart from the email alone, so it asks its
 * configured discovery host (the public build uses flowone.pro; white-label
 * builds point VITE_DISCOVERY_HOST at their own server, so they never phone
 * home). Each server answers ONLY for the domains it actually hosts:
 *
 *   - domain present in mail_accounts on this server -> hosted here, return
 *     this server's public origin (api/frontend URL).
 *   - otherwise -> not hosted here, return the email.<domain> convention so
 *     the app can target the dedicated deployment directly.
 *
 * PUBLIC endpoint (apps hit it before authenticating). It never reveals
 * anything beyond "do I host this domain": no account data is returned.
 *
 *   GET /api/server-discovery?domain=pixelranger.hu
 *   GET /api/server-discovery?email=robert@magyarszinhaz.hu
 */
class ServerDiscoveryController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function discover(Request $request): Response
    {
        $domain = $this->resolveDomain($request);
        if ($domain === '') {
            return Response::error('A domain or email query parameter is required', 400);
        }

        $hosted = $this->isDomainHostedHere($domain);

        if ($hosted) {
            return Response::json([
                'success' => true,
                'domain'  => $domain,
                'hosted'  => true,
                'api_url' => $this->selfOrigin(),
            ]);
        }

        // Not hosted on this server: assume a dedicated deployment that follows
        // the email.<domain> convention. The app will target it directly.
        return Response::json([
            'success' => true,
            'domain'  => $domain,
            'hosted'  => false,
            'api_url' => 'https://email.' . $domain,
        ]);
    }

    // ---- helpers -------------------------------------------------------

    /**
     * Read the email domain from ?domain= or ?email=. Accepts a bare domain,
     * a full email, or an email.<domain> / mail.<domain> host (strips the
     * subdomain prefix back to the registrable domain).
     */
    private function resolveDomain(Request $request): string
    {
        $email = (string) ($request->getQuery('email') ?? '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return strtolower(substr(strrchr($email, '@'), 1));
        }

        $domain = strtolower(trim((string) ($request->getQuery('domain') ?? '')));
        $domain = preg_replace('/:\d+$/', '', $domain);     // strip :port
        $domain = preg_replace('#^https?://#', '', $domain); // strip scheme if pasted
        $domain = rtrim($domain, '/');
        $domain = preg_replace('/^(email|mail|webmail)\./', '', $domain);

        // Basic hostname sanity: letters/digits/hyphen labels with a dot.
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9-]{1,63}\.)+[a-z]{2,63}$/', $domain)) {
            return '';
        }
        return $domain;
    }

    /**
     * Whether this server holds at least one mailbox for the domain. Uses the
     * mail_accounts table (the canonical mailbox list, queried the same way as
     * the colleague sync). Defensive: on any DB error we claim the domain so
     * existing users on this server are never redirected to a non-existent
     * email.<domain> host. The app caches successful lookups, so a transient
     * outage cannot misroute a dedicated customer permanently.
     */
    private function isDomainHostedHere(string $domain): bool
    {
        try {
            $db = Database::getConnection($this->config);

            try {
                $stmt = $db->prepare('SELECT 1 FROM mail_accounts WHERE domain = ? LIMIT 1');
                $stmt->execute([$domain]);
                if ($stmt->fetchColumn() !== false) {
                    return true;
                }
            } catch (\Throwable $e) {
                // domain column may be absent on some schemas; fall through.
            }

            $stmt = $db->prepare('SELECT 1 FROM mail_accounts WHERE email LIKE ? LIMIT 1');
            $stmt->execute(['%@' . $domain]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            error_log('ServerDiscovery: hosted-check failed for ' . $domain . ': ' . $e->getMessage());
            return true; // fail safe: keep existing users pointed at this server
        }
    }

    /** This server's public origin (no trailing slash, no /api suffix). */
    private function selfOrigin(): string
    {
        $origin = (string) ($this->config['app']['frontend_url'] ?? '');
        if ($origin === '') {
            // Derive from the request host as a last resort.
            $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $host = preg_replace('/:\d+$/', '', $host);
            $origin = $host !== '' ? 'https://' . $host : '';
        }
        return rtrim($origin, '/');
    }
}

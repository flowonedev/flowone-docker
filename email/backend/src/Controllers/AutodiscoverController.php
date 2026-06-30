<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;

/**
 * AutodiscoverController
 * ---------------------------------------------------------------
 * Serves the mail client auto-configuration documents so users only
 * type their email + password and the client fills in IMAP/SMTP:
 *
 *   - Outlook:      POST /autodiscover/autodiscover.xml
 *   - Thunderbird:  GET  /mail/config-v1.1.xml?emailaddress=…
 *                   GET  /.well-known/autoconfig/mail/config-v1.1.xml
 *
 * All endpoints are PUBLIC (clients hit them before authenticating).
 * They resolve the user's domain from the request and return
 * mail.<domain> (the host the MX / A records point at, created by the
 * Panel DNS provisioning step) with IMAP 993/SSL and SMTP 587/STARTTLS.
 *
 * The autodiscover.<domain> / autoconfig.<domain> subdomains are CNAMEd
 * to this server by DnsZoneCreateStep, so these requests land here.
 */
class AutodiscoverController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Microsoft Outlook autodiscover. Outlook POSTs an XML body containing
     * <EMailAddress>. We reply with IMAP + SMTP settings.
     */
    public function outlook(Request $request): Response
    {
        $email = $this->emailFromOutlookBody();
        $domain = $this->resolveDomain($request, $email);
        $mailHost = $this->mailHost($domain);
        $login = $email ?: ('%EMAILADDRESS%');

        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">' . "\n"
            . '  <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a">' . "\n"
            . '    <Account>' . "\n"
            . '      <AccountType>email</AccountType>' . "\n"
            . '      <Action>settings</Action>' . "\n"
            . '      <Protocol>' . "\n"
            . '        <Type>IMAP</Type>' . "\n"
            . '        <Server>' . $this->x($mailHost) . '</Server>' . "\n"
            . '        <Port>993</Port>' . "\n"
            . '        <DomainRequired>off</DomainRequired>' . "\n"
            . '        <LoginName>' . $this->x($login) . '</LoginName>' . "\n"
            . '        <SPA>off</SPA>' . "\n"
            . '        <SSL>on</SSL>' . "\n"
            . '        <AuthRequired>on</AuthRequired>' . "\n"
            . '      </Protocol>' . "\n"
            . '      <Protocol>' . "\n"
            . '        <Type>SMTP</Type>' . "\n"
            . '        <Server>' . $this->x($mailHost) . '</Server>' . "\n"
            . '        <Port>587</Port>' . "\n"
            . '        <DomainRequired>off</DomainRequired>' . "\n"
            . '        <LoginName>' . $this->x($login) . '</LoginName>' . "\n"
            . '        <SPA>off</SPA>' . "\n"
            . '        <SSL>on</SSL>' . "\n"
            . '        <Encryption>TLS</Encryption>' . "\n"
            . '        <AuthRequired>on</AuthRequired>' . "\n"
            . '        <UsePOPAuth>on</UsePOPAuth>' . "\n"
            . '        <SMTPLast>off</SMTPLast>' . "\n"
            . '      </Protocol>' . "\n"
            . '    </Account>' . "\n"
            . '  </Response>' . "\n"
            . '</Autodiscover>' . "\n";

        return Response::raw($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /**
     * Mozilla Thunderbird / ISPDB autoconfig. Client GETs with
     * ?emailaddress=user@domain (older clients omit it and rely on the
     * autoconfig.<domain> host).
     */
    public function thunderbird(Request $request): Response
    {
        $email = (string) ($request->getQuery('emailaddress') ?? '');
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : '';
        $domain = $this->resolveDomain($request, $email);
        $mailHost = $this->mailHost($domain);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<clientConfig version="1.1">' . "\n"
            . '  <emailProvider id="' . $this->x($domain) . '">' . "\n"
            . '    <domain>' . $this->x($domain) . '</domain>' . "\n"
            . '    <displayName>' . $this->x($domain) . ' Mail</displayName>' . "\n"
            . '    <displayShortName>' . $this->x($domain) . '</displayShortName>' . "\n"
            . '    <incomingServer type="imap">' . "\n"
            . '      <hostname>' . $this->x($mailHost) . '</hostname>' . "\n"
            . '      <port>993</port>' . "\n"
            . '      <socketType>SSL</socketType>' . "\n"
            . '      <authentication>password-cleartext</authentication>' . "\n"
            . '      <username>%EMAILADDRESS%</username>' . "\n"
            . '    </incomingServer>' . "\n"
            . '    <outgoingServer type="smtp">' . "\n"
            . '      <hostname>' . $this->x($mailHost) . '</hostname>' . "\n"
            . '      <port>587</port>' . "\n"
            . '      <socketType>STARTTLS</socketType>' . "\n"
            . '      <authentication>password-cleartext</authentication>' . "\n"
            . '      <username>%EMAILADDRESS%</username>' . "\n"
            . '    </outgoingServer>' . "\n"
            . '  </emailProvider>' . "\n"
            . '</clientConfig>' . "\n";

        return Response::raw($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    // ---- helpers -------------------------------------------------------

    private function emailFromOutlookBody(): string
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw !== '' && preg_match('#<EMailAddress>\s*([^<\s]+)\s*</EMailAddress>#i', $raw, $m)) {
            $e = strtolower(trim($m[1]));
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                return $e;
            }
        }
        return '';
    }

    private function resolveDomain(Request $request, string $email): string
    {
        if ($email !== '' && str_contains($email, '@')) {
            return substr(strrchr($email, '@'), 1);
        }
        // Fall back to the Host header (autodiscover.<domain> / autoconfig.<domain>).
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        $host = preg_replace('/^(autodiscover|autoconfig)\./', '', $host);
        return $host ?: 'localhost';
    }

    private function mailHost(string $domain): string
    {
        $domain = ltrim($domain, '.');
        if ($domain === '' || $domain === 'localhost') {
            return 'localhost';
        }
        // Already a mail.* host (request hit mail.<domain> directly).
        if (str_starts_with($domain, 'mail.')) {
            return $domain;
        }
        return 'mail.' . $domain;
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

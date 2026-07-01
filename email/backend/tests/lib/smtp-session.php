<?php
/**
 * SmtpSession — a minimal SMTP submission client for server-side test scripts.
 *
 * Just enough of RFC 5321/4954 to prove the FlowOne mail-pod submission path:
 * greeting -> EHLO -> STARTTLS -> EHLO -> AUTH LOGIN -> MAIL/RCPT/DATA. It is
 * self-signed-cert tolerant on purpose (dry-run boxes serve a self-signed cert
 * on :587), so it MUST NOT be used as a general-purpose mailer in app code.
 *
 * Test infrastructure only. Never used by production request paths.
 */

declare(strict_types=1);

final class SmtpSession
{
    /** @var resource */
    private $fp;

    /** Human-readable protocol transcript (for --verbose dumps on failure). */
    public array $transcript = [];

    public function __construct(string $host, int $port, float $timeout = 10.0)
    {
        // Dry-run boxes present a self-signed cert on the submission port, so we
        // explicitly tolerate it here — this client only ever talks to the pod
        // under test, never to an untrusted peer.
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]]);

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($fp === false) {
            throw new \RuntimeException("SMTP connect failed {$host}:{$port} ({$errno} {$errstr})");
        }
        $this->fp = $fp;
        stream_set_timeout($this->fp, (int) $timeout);
        $this->expect($this->read(), ['220'], 'greeting');
    }

    /** Read one (possibly multi-line) SMTP reply. */
    private function read(): string
    {
        $data = '';
        while (($line = fgets($this->fp)) !== false) {
            $data .= $line;
            // A hyphen in the 4th column ("250-") means more lines follow.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $this->transcript[] = 'S: ' . trim($data);
        return $data;
    }

    /** Send a command line and return the server's reply. */
    public function cmd(string $line): string
    {
        $this->transcript[] = 'C: ' . $line;
        fwrite($this->fp, $line . "\r\n");
        return $this->read();
    }

    public function code(string $resp): string
    {
        return substr($resp, 0, 3);
    }

    private function expect(string $resp, array $codes, string $stage): void
    {
        if (!in_array($this->code($resp), $codes, true)) {
            throw new \RuntimeException(
                "SMTP {$stage}: expected [" . implode('/', $codes) . '], got: ' . trim($resp)
            );
        }
    }

    public function ehlo(string $who = 'flowone-test.local'): void
    {
        $this->expect($this->cmd("EHLO {$who}"), ['250'], 'EHLO');
    }

    public function startTls(): void
    {
        $this->expect($this->cmd('STARTTLS'), ['220'], 'STARTTLS');
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (@stream_socket_enable_crypto($this->fp, true, $crypto) !== true) {
            throw new \RuntimeException('SMTP STARTTLS handshake failed');
        }
    }

    /**
     * AUTH LOGIN. Returns the final SMTP code (235 = ok, 535 = bad creds) instead
     * of throwing on 535, so negative-auth tests can assert the rejection.
     */
    public function authLogin(string $user, string $pass): string
    {
        $this->expect($this->cmd('AUTH LOGIN'), ['334'], 'AUTH');
        $this->expect($this->cmd(base64_encode($user)), ['334'], 'AUTH-user');
        // Don't log the raw secret.
        $this->transcript[] = 'C: <base64 password>';
        fwrite($this->fp, base64_encode($pass) . "\r\n");
        return $this->code($this->read());
    }

    public function sendMessage(string $from, string $to, string $data): void
    {
        $this->expect($this->cmd("MAIL FROM:<{$from}>"), ['250'], 'MAIL FROM');
        $this->expect($this->cmd("RCPT TO:<{$to}>"), ['250', '251'], 'RCPT TO');
        $this->expect($this->cmd('DATA'), ['354'], 'DATA');

        foreach (preg_split('/\r\n|\r|\n/', $data) as $l) {
            // Dot-stuffing: a leading '.' must be doubled or it ends the message.
            if (isset($l[0]) && $l[0] === '.') {
                $l = '.' . $l;
            }
            fwrite($this->fp, $l . "\r\n");
        }
        $this->expect($this->cmd('.'), ['250'], 'end-of-DATA');
    }

    public function quit(): void
    {
        if (is_resource($this->fp)) {
            @fwrite($this->fp, "QUIT\r\n");
            @fclose($this->fp);
        }
    }

    public function __destruct()
    {
        $this->quit();
    }
}

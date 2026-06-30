<?php

namespace Webmail\Addons\EmailMarketing\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\EmailMarketing\Services\UnsubscribeService;

/**
 * UnsubscribeController - Public unsubscribe endpoints + authenticated management
 * 
 * Public (no auth):
 *   GET  /api/unsubscribe/{token}  - Branded landing page
 *   POST /api/unsubscribe/{token}  - One-click unsubscribe (RFC 8058)
 * 
 * Authenticated:
 *   GET    /email-marketing/unsubscribes        - List unsubscribed addresses
 *   DELETE /email-marketing/unsubscribes/{email} - Resubscribe
 */
class UnsubscribeController extends BaseController
{
    private ?UnsubscribeService $unsubService = null;
    
    private function getUnsubService(): UnsubscribeService
    {
        if ($this->unsubService === null) {
            $this->unsubService = new UnsubscribeService($this->config);
        }
        return $this->unsubService;
    }
    
    /**
     * GET /api/unsubscribe/{token}
     * Show branded unsubscribe confirmation page (public, no auth)
     */
    public function showPage(Request $request): Response
    {
        $token = $request->getParam('token');
        $service = $this->getUnsubService();
        $data = $service->validateToken($token);
        
        if (!$data) {
            return $this->renderHtmlPage('Error', 
                '<div class="text-center">
                    <div class="icon-circle err">
                        <span class="material-symbols-rounded">link_off</span>
                    </div>
                    <h2>Invalid Link</h2>
                    <p class="desc">This unsubscribe link is invalid or has expired.</p>
                </div>', 
                'error');
        }
        
        $sender = htmlspecialchars($data['sender']);
        $recipient = htmlspecialchars($data['recipient']);
        $domain = htmlspecialchars(explode('@', $data['sender'])[1] ?? $data['sender']);
        
        $alreadyUnsubscribed = $service->isUnsubscribed($data['sender'], $data['recipient']);
        
        if ($alreadyUnsubscribed) {
            return $this->renderHtmlPage($domain, 
                '<div class="text-center">
                    <div class="icon-circle ok">
                        <span class="material-symbols-rounded">check_circle</span>
                    </div>
                    <h2>Already Unsubscribed</h2>
                    <p class="desc">You have already unsubscribed from emails sent by <strong>' . $sender . '</strong>.</p>
                </div>',
                'success');
        }
        
        $actionUrl = htmlspecialchars($_SERVER['REQUEST_URI'] ?? "/api/unsubscribe/{$token}");
        
        return $this->renderHtmlPage($domain, '
            <div class="text-center">
                <div class="icon-circle warn">
                    <span class="material-symbols-rounded">mail_off</span>
                </div>
                <h2>Unsubscribe</h2>
                <p class="desc">
                    You are unsubscribing from emails sent by<br>
                    <strong>' . $sender . '</strong>
                </p>
                <form method="POST" action="' . $actionUrl . '">
                    <div class="form-group">
                        <label>Reason (optional)</label>
                        <select name="reason">
                            <option value="">-- Select a reason --</option>
                            <option value="too_frequent">Too many emails</option>
                            <option value="not_relevant">Not relevant to me</option>
                            <option value="never_subscribed">I never subscribed</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-unsub">
                        <span class="material-symbols-rounded">unsubscribe</span>
                        Confirm Unsubscribe
                    </button>
                </form>
                <p class="meta">' . $recipient . '</p>
            </div>',
            'form');
    }
    
    /**
     * POST /api/unsubscribe/{token}
     * Handle unsubscribe action (both form submit and RFC 8058 one-click)
     */
    public function handleUnsubscribe(Request $request): Response
    {
        $token = $request->getParam('token');
        $service = $this->getUnsubService();
        $data = $service->validateToken($token);
        
        if (!$data) {
            $contentType = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'text/html') !== false || empty($contentType) || stripos($contentType, 'form') !== false) {
                return $this->renderHtmlPage('Error', 
                    '<div class="text-center">
                        <div class="icon-circle err">
                            <span class="material-symbols-rounded">link_off</span>
                        </div>
                        <h2>Invalid Link</h2>
                        <p class="desc">This unsubscribe link is invalid or has expired.</p>
                    </div>', 
                    'error');
            }
            return Response::error('Invalid unsubscribe token', 400);
        }
        
        $reason = $request->input('reason') ?? null;
        if ($reason === '') $reason = null;
        
        $service->unsubscribe($data['sender'], $data['recipient'], $reason);
        
        // Remove from mailing lists + cancel pending queue items
        $service->cleanupAfterUnsubscribe($data['sender'], $data['recipient']);
        
        // RFC 8058 one-click: check if this is a machine POST (List-Unsubscribe-Post)
        $body = file_get_contents('php://input') ?? '';
        $isOneClick = (stripos($body, 'List-Unsubscribe=One-Click') !== false);
        
        if ($isOneClick) {
            return Response::success(null, 'Unsubscribed');
        }
        
        $sender = htmlspecialchars($data['sender']);
        $domain = htmlspecialchars(explode('@', $data['sender'])[1] ?? $data['sender']);
        
        return $this->renderHtmlPage($domain, '
            <div class="text-center">
                <div class="icon-circle ok">
                    <span class="material-symbols-rounded">check_circle</span>
                </div>
                <h2>Unsubscribed</h2>
                <p class="desc">
                    You have been successfully unsubscribed from emails sent by<br>
                    <strong>' . $sender . '</strong>.
                </p>
                <p class="meta mt-20">You can close this page.</p>
            </div>',
            'success');
    }
    
    /**
     * GET /email-marketing/unsubscribes
     * List unsubscribed addresses (authenticated)
     */
    public function listUnsubscribes(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $service = $this->getUnsubService();
        $limit = min((int)($request->getParam('limit') ?? 100), 500);
        $offset = (int)($request->getParam('offset') ?? 0);
        
        $list = $service->getUnsubscribeList($this->userEmail, $limit, $offset);
        $total = $service->getUnsubscribeCount($this->userEmail);
        
        return Response::success([
            'unsubscribes' => $list,
            'total' => $total,
        ]);
    }
    
    /**
     * DELETE /email-marketing/unsubscribes/{email}
     * Resubscribe an address (authenticated)
     */
    public function resubscribe(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $email = urldecode($request->getParam('email') ?? '');
        if (empty($email)) {
            return Response::error('Email is required', 400);
        }
        
        $service = $this->getUnsubService();
        $result = $service->resubscribe($this->userEmail, $email);
        
        if (!$result) {
            return Response::error('Email not found in unsubscribe list', 404);
        }
        
        return Response::success(null, 'Resubscribed');
    }
    
    /**
     * Render a self-contained HTML page for the public unsubscribe flow.
     */
    private function renderHtmlPage(string $title, string $content, string $type = 'form'): Response
    {
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribe - ' . htmlspecialchars($title) . '</title>
    <link rel="stylesheet" href="/fonts/material-symbols-rounded/font.css" />
    <style nonce="' . (defined('CSP_NONCE') ? CSP_NONCE : '') . '">
        * { box-sizing: border-box; margin: 0; padding: 0; }

        /* Light theme (default) */
        :root {
            --bg: #f4f5f7;
            --card-bg: #ffffff;
            --card-border: #e5e7eb;
            --brand-border: #f3f4f6;
            --brand-color: #6b7280;
            --heading: #111827;
            --text: #6b7280;
            --text-strong: #22c55e;
            --label: #6b7280;
            --input-bg: #f9fafb;
            --input-border: #d1d5db;
            --input-text: #374151;
            --meta: #9ca3af;
        }

        /* Dark theme */
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1117;
                --card-bg: #1a1d27;
                --card-border: #2a2d3a;
                --brand-border: #2a2d3a;
                --brand-color: #9ca3af;
                --heading: #f3f4f6;
                --text: #9ca3af;
                --text-strong: #22c55e;
                --label: #9ca3af;
                --input-bg: #0f1117;
                --input-border: #2a2d3a;
                --input-text: #e5e7eb;
                --meta: #6b7280;
            }
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 40px 32px;
            max-width: 420px;
            width: 100%;
        }
        .brand {
            text-align: center;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--brand-border);
        }
        .brand-domain {
            font-size: 13px;
            font-weight: 600;
            color: var(--brand-color);
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .icon-circle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            border-radius: 50%;
            margin-bottom: 20px;
        }
        .icon-circle.warn { background: rgba(234, 179, 8, 0.12); color: #eab308; }
        .icon-circle.ok { background: rgba(34, 197, 94, 0.12); color: #22c55e; }
        .icon-circle.err { background: rgba(239, 68, 68, 0.12); color: #ef4444; }
        .icon-circle .material-symbols-rounded { font-size: 32px; }
        h2 { font-size: 20px; font-weight: 700; color: var(--heading); margin-bottom: 8px; }
        .desc { font-size: 14px; color: var(--text); line-height: 1.6; }
        .desc strong { color: var(--text-strong); font-weight: 600; }
        .form-group { margin: 24px 0 20px; text-align: left; }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--label);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        select {
            width: 100%;
            padding: 12px 14px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 12px;
            font-size: 14px;
            color: var(--input-text);
            appearance: auto;
            transition: border-color 0.2s;
        }
        select:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
        .btn-unsub {
            width: 100%;
            padding: 14px;
            background: #ef4444;
            color: #fff;
            border: none;
            border-radius: 999px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-unsub:hover { background: #dc2626; transform: translateY(-1px); }
        .btn-unsub:active { transform: translateY(0); }
        .btn-unsub .material-symbols-rounded { font-size: 20px; }
        .meta {
            margin-top: 20px;
            font-size: 12px;
            color: var(--meta);
            text-align: center;
        }
        .text-center { text-align: center; }
        .mt-20 { margin-top: 20px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <div class="brand-domain">' . htmlspecialchars($title) . '</div>
        </div>
        ' . $content . '
    </div>
</body>
</html>';
        
        $response = Response::raw($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        return $response;
    }
}

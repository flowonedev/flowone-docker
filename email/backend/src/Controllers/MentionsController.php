<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\Mentions\MentionsService;
use Webmail\Utils\EmailNormalizer;

/**
 * REST endpoints for the Mentions subsystem.
 *
 * Routes (wired in routes.php):
 *
 *   GET  /mentions/for-message?message_id=<msgid>
 *       The mention chips + trust badge to render inside the email view.
 *
 *   GET  /mentions/suggest?q=<token>&limit=8
 *       Autocomplete for the compose @-popup. Wraps the existing contacts
 *       search and adds a domain-priority sort (colleagues first).
 *
 * All endpoints require auth.
 */
class MentionsController extends BaseController
{
    private ?MentionsService $service = null;

    public function __construct(array $config)
    {
        parent::__construct($config);
    }

    private function getService(): MentionsService
    {
        if (!$this->service) {
            $this->service = new MentionsService($this->config);
        }
        return $this->service;
    }

    public function forMessage(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email     = $this->getActiveEmail();
        $messageId = (string) $request->getQuery('message_id', '');
        if ($messageId === '') {
            return Response::error('message_id is required', 400);
        }

        return Response::success([
            'mentions' => $this->getService()->getMentionsForMessage($email, $messageId),
        ]);
    }

    /**
     * Autocomplete for the @-popup. Sourced from the existing ContactsService;
     * we filter to addresses that pass EmailNormalizer and prepend any
     * exact-prefix domain match (so typing `@robert` floats
     * `robert@yourdomain.com` ahead of `robert@vendor.com`).
     */
    public function suggest(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $q     = trim((string) $request->getQuery('q', ''));
        $limit = max(1, min(20, (int) $request->getQuery('limit', 8)));
        if ($q === '') {
            return Response::success(['suggestions' => []]);
        }

        // Lean on the contacts service that the compose autocomplete already
        // uses — same backing store, same ordering.
        try {
            $contactsService = new \Webmail\Services\ContactsService($this->config);
            $contacts = $contactsService->searchContacts($activeEmail, $q, $limit * 2);
        } catch (\Throwable $e) {
            error_log('[MentionsController::suggest] ' . $e->getMessage());
            $contacts = [];
        }

        $ownDomain = EmailNormalizer::domainOf($activeEmail);
        $suggestions = [];
        foreach ($contacts as $c) {
            $email = EmailNormalizer::normalize($c['contact_email'] ?? '');
            if ($email === null) continue;
            $domain = EmailNormalizer::domainOf($email);
            $suggestions[] = [
                'email'   => $email,
                'name'    => (string) ($c['contact_name'] ?? ''),
                'display' => trim(($c['contact_name'] ?? '') . ' <' . $email . '>'),
                // Floats colleagues (= same domain) to the top of the popup.
                'is_colleague' => $domain !== null && $ownDomain !== null && $domain === $ownDomain,
                'use_count' => (int) ($c['use_count'] ?? 0),
            ];
        }

        usort($suggestions, static function ($a, $b) {
            if ($a['is_colleague'] !== $b['is_colleague']) {
                return $a['is_colleague'] ? -1 : 1;
            }
            return $b['use_count'] <=> $a['use_count'];
        });

        return Response::success([
            'suggestions' => array_slice($suggestions, 0, $limit),
        ]);
    }
}

<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\ContactsService;

class ContactsController extends BaseController
{
    private ?\PDO $db = null;
    private ?ContactsService $contactsService = null;

    private function getDb(): \PDO
    {
        if ($this->db === null) {
            $this->db = \Webmail\Core\Database::getConnection($this->config);
        }
        return $this->db;
    }

    private function getContactsService(): ContactsService
    {
        if ($this->contactsService === null) {
            // Pass config so the cache reconciles with the real address book
            // (threshold auto-add + unified, flagged autocomplete).
            $this->contactsService = new ContactsService($this->getDb(), $this->config);
        }
        return $this->contactsService;
    }

    /** Shape a merged contact row for the frontend, including cross-layer flags. */
    private function formatSuggestion(array $c): array
    {
        return [
            'email' => $c['contact_email'],
            'name' => $c['contact_name'],
            'display' => $c['contact_name']
                ? $c['contact_name'] . ' <' . $c['contact_email'] . '>'
                : $c['contact_email'],
            'use_count' => (int) ($c['use_count'] ?? 0),
            'contact_id' => isset($c['contact_id']) && $c['contact_id'] !== null ? (int) $c['contact_id'] : null,
            'is_saved' => !empty($c['is_saved']),
            'is_synced' => !empty($c['is_synced']),
            'is_client' => !empty($c['is_client']),
            'client_id' => isset($c['client_id']) && $c['client_id'] !== null ? (int) $c['client_id'] : null,
        ];
    }

    /**
     * Search contacts for autocomplete
     */
    public function search(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $query = $request->getQuery('q', '');
        $limit = min((int)$request->getQuery('limit', 10), 50);
        
        // Get the active email (supports account switching)
        $activeEmail = $this->getActiveEmailFromRequest($request);
        
        $service = $this->getContactsService();
        
        if (strlen($query) >= 1) {
            $contacts = $service->searchContacts($activeEmail, $query, $limit);
        } else {
            // If no query, return recent contacts
            $contacts = $service->getRecentContacts($activeEmail, $limit);
        }

        $formatted = array_map([$this, 'formatSuggestion'], $contacts);

        return Response::success(['contacts' => $formatted]);
    }

    /**
     * Get recent contacts
     */
    public function recent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $limit = min((int)$request->getQuery('limit', 20), 100);
        $activeEmail = $this->getActiveEmailFromRequest($request);
        
        $service = $this->getContactsService();
        $contacts = $service->getRecentContacts($activeEmail, $limit);

        $formatted = array_map([$this, 'formatSuggestion'], $contacts);

        return Response::success(['contacts' => $formatted]);
    }

    /**
     * Save a suggested/recipient address into the real (synced) address book.
     * Promotes it out of the non-synced "Other contacts" pool if it's already
     * there. Body: { email, name? }.
     */
    public function save(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = strtolower(trim((string) $request->input('email', '')));
        $name = $request->input('name');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::error('A valid email is required', 400);
        }

        $activeEmail = $this->getActiveEmailFromRequest($request);
        $contact = $this->getContactsService()->saveToAddressBook($activeEmail, $email, $name ? (string) $name : null);

        if (!$contact) {
            return Response::error('Could not save contact', 500);
        }
        return Response::success(['contact' => $contact], 'Saved to contacts');
    }

    /**
     * Delete a contact
     */
    public function delete(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $contactEmail = $request->getParam('email', '');
        if (!$contactEmail) {
            return Response::error('Contact email required', 400);
        }
        
        $activeEmail = $this->getActiveEmailFromRequest($request);
        $service = $this->getContactsService();
        
        if ($service->deleteContact($activeEmail, urldecode($contactEmail))) {
            return Response::success(['message' => 'Contact deleted']);
        }
        
        return Response::error('Failed to delete contact', 500);
    }

    /**
     * Import contacts from tracking history
     */
    public function import(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmailFromRequest($request);
        $service = $this->getContactsService();
        
        $imported = $service->importFromTrackingHistory($activeEmail);
        
        return Response::success([
            'message' => "Imported $imported contacts from email history",
            'count' => $imported
        ]);
    }

    /**
     * Get the active email address (supports account switching)
     */
    private function getActiveEmailFromRequest(Request $request): string
    {
        $accountId = $request->getHeader('X-Account-ID');
        
        if ($accountId && $accountId !== 'primary' && $accountId !== 'null') {
            try {
                $db = $this->getDb();
                
                // Check webmail_accounts table
                $stmt = $db->prepare('SELECT account_email FROM webmail_accounts WHERE id = ?');
                $stmt->execute([(int)$accountId]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($result) {
                    return $result['account_email'];
                }
                
                // Check OAuth accounts
                $stmt = $db->prepare('SELECT oauth_email FROM webmail_oauth_tokens WHERE id = ?');
                $stmt->execute([(int)$accountId]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($result) {
                    return $result['oauth_email'];
                }
            } catch (\Exception $e) {
                error_log("ContactsController getActiveEmail error: " . $e->getMessage());
            }
        }
        
        return $this->userEmail;
    }
}

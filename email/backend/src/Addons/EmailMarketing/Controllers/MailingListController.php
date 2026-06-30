<?php

namespace Webmail\Addons\EmailMarketing\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\EmailMarketing\Services\MailingListService;

/**
 * MailingListController - API endpoints for mailing list management
 * 
 * Endpoints:
 * - GET /mailing-lists - List all mailing lists
 * - GET /mailing-lists/:id - Get list with contacts
 * - POST /mailing-lists - Create list
 * - PUT /mailing-lists/:id - Update list
 * - DELETE /mailing-lists/:id - Delete list
 * 
 * - GET /mailing-lists/:id/contacts - Get contacts
 * - POST /mailing-lists/:id/contacts - Add contact
 * - PUT /mailing-lists/contacts/:id - Update contact
 * - DELETE /mailing-lists/contacts/:id - Delete contact
 * - POST /mailing-lists/contacts/bulk-delete - Bulk delete contacts
 * 
 * - POST /mailing-lists/:id/import - Import from Excel/CSV
 * - GET /mailing-lists/:id/emails - Get emails for sending
 */
class MailingListController extends BaseController
{
    private ?MailingListService $service = null;
    
    private function getService(): MailingListService
    {
        if ($this->service === null) {
            try {
                $this->service = new MailingListService($this->config);
            } catch (\Throwable $e) {
                error_log("MailingListController: Failed to create service - " . $e->getMessage());
                throw $e;
            }
        }
        return $this->service;
    }
    
    // ========================================
    // LIST ENDPOINTS
    // ========================================
    
    /**
     * GET /mailing-lists - List all mailing lists
     */
    public function list(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        try {
            $service = $this->getService();
            $lists = $service->getLists($this->userEmail);
            return Response::success(['lists' => $lists]);
        } catch (\Throwable $e) {
            error_log("MailingListController list error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            error_log("Stack trace: " . $e->getTraceAsString());
            return Response::error('Server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * POST /mailing-lists - Create list  
     */
    public function create(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $data = $request->input();
        
        if (empty($data['name'])) {
            return Response::error('Name is required', 400);
        }
        
        try {
            $service = $this->getService();
            $id = $service->createList($this->userEmail, $data);
            return Response::success(['id' => $id], 201);
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                return Response::error('A list with this name already exists', 400);
            }
            error_log("MailingListController create PDO error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return Response::error('Database error: ' . $e->getMessage(), 500);
        } catch (\Throwable $e) {
            error_log("MailingListController create error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            error_log("Stack trace: " . $e->getTraceAsString());
            return Response::error('Server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * GET /mailing-lists/:id - Get list with contacts
     */
    public function get(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int) $request->getParam('id');
        
        $service = $this->getService();
        $list = $service->getList($id, $this->userEmail);
        
        if (!$list) {
            return Response::error('List not found', 404);
        }
        
        return Response::success(['list' => $list]);
    }
    
    /**
     * PUT /mailing-lists/:id - Update list
     */
    public function update(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int) $request->getParam('id');
        $data = $request->input();
        
        $service = $this->getService();
        
        if (!$service->updateList($id, $this->userEmail, $data)) {
            return Response::error('List not found', 404);
        }
        
        return Response::success(['updated' => true]);
    }
    
    /**
     * DELETE /mailing-lists/:id - Delete list
     */
    public function delete(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int) $request->getParam('id');
        
        $service = $this->getService();
        
        if (!$service->deleteList($id, $this->userEmail)) {
            return Response::error('List not found or already deleted', 404);
        }
        
        return Response::success(['deleted' => true]);
    }
    
    // ========================================
    // CONTACT ENDPOINTS
    // ========================================
    
    /**
     * GET /mailing-lists/:id/contacts - Get contacts
     */
    public function getContacts(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $listId = (int) $request->getParam('id');
        
        $service = $this->getService();
        $contacts = $service->getContacts($listId, $this->userEmail);
        
        return Response::success(['contacts' => $contacts]);
    }
    
    /**
     * POST /mailing-lists/:id/contacts - Add contact
     */
    public function addContact(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $listId = (int) $request->getParam('id');
        $data = $request->input();
        
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return Response::error('Valid email is required', 400);
        }
        
        $service = $this->getService();
        $id = $service->addContact($listId, $this->userEmail, $data);
        
        if ($id === null) {
            return Response::error('Contact already exists in this list or list not found', 400);
        }
        
        return Response::success(['id' => $id], 201);
    }
    
    /**
     * PUT /mailing-lists/contacts/:id - Update contact
     */
    public function updateContact(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $contactId = (int) $request->getParam('contactId');
        $data = $request->input();
        
        $service = $this->getService();
        
        if (!$service->updateContact($contactId, $this->userEmail, $data)) {
            return Response::error('Contact not found', 404);
        }
        
        return Response::success(['updated' => true]);
    }
    
    /**
     * DELETE /mailing-lists/contacts/:id - Delete contact
     */
    public function deleteContact(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $contactId = (int) $request->getParam('contactId');
        
        $service = $this->getService();
        
        if (!$service->deleteContact($contactId, $this->userEmail)) {
            return Response::error('Contact not found', 404);
        }
        
        return Response::success(['deleted' => true]);
    }
    
    /**
     * POST /mailing-lists/contacts/bulk-delete - Bulk delete contacts
     */
    public function bulkDeleteContacts(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $data = $request->input();
        $contactIds = $data['contact_ids'] ?? [];
        
        if (empty($contactIds) || !is_array($contactIds)) {
            return Response::error('Contact IDs are required', 400);
        }
        
        $service = $this->getService();
        $deleted = $service->deleteContacts($contactIds, $this->userEmail);
        
        return Response::success(['deleted' => $deleted]);
    }
    
    // ========================================
    // IMPORT ENDPOINTS
    // ========================================
    
    /**
     * POST /mailing-lists/:id/import - Import from Excel/CSV
     */
    public function import(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $listId = (int) $request->getParam('id');
        $data = $request->input();
        
        // Contacts can be sent directly as parsed array or as CSV content
        $contacts = $data['contacts'] ?? [];
        $csvContent = $data['csv_content'] ?? null;
        $filename = $data['filename'] ?? null;
        
        $service = $this->getService();
        
        // Parse CSV if content provided
        if ($csvContent && empty($contacts)) {
            $contacts = $service->parseExcelData($csvContent, 'csv');
        }
        
        if (empty($contacts)) {
            return Response::error('No contacts to import', 400);
        }
        
        $result = $service->importContacts($listId, $this->userEmail, $contacts, $filename);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success([
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors']
        ]);
    }
    
    /**
     * GET /mailing-lists/:id/emails - Get emails for sending
     */
    public function getEmails(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $listId = (int) $request->getParam('id');
        
        $service = $this->getService();
        $emails = $service->getListEmails($listId, $this->userEmail);
        
        return Response::success(['emails' => $emails]);
    }
    
    // ========================================
    // CUSTOM FIELD ENDPOINTS
    // ========================================
    
    /**
     * GET /mailing-lists/:id/custom-fields
     */
    public function getCustomFields(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $listId = (int) $request->getParam('id');
        $service = $this->getService();
        
        return Response::success(['fields' => $service->getCustomFields($listId, $this->userEmail)]);
    }
    
    /**
     * POST /mailing-lists/:id/custom-fields
     */
    public function createCustomField(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $listId = (int) $request->getParam('id');
        $data = $request->input();
        
        if (empty($data['field_label'])) {
            return Response::error('Field label is required', 400);
        }
        
        $service = $this->getService();
        $id = $service->createCustomField($listId, $this->userEmail, $data);
        
        if ($id === null) {
            return Response::error('Failed to create field (duplicate key or list not found)', 400);
        }
        
        return Response::success(['id' => $id], 201);
    }
    
    /**
     * PUT /mailing-lists/custom-fields/:fieldId
     */
    public function updateCustomField(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $fieldId = (int) $request->getParam('fieldId');
        $data = $request->input();
        
        $service = $this->getService();
        if (!$service->updateCustomField($fieldId, $this->userEmail, $data)) {
            return Response::error('Field not found', 404);
        }
        
        return Response::success(['updated' => true]);
    }
    
    /**
     * DELETE /mailing-lists/custom-fields/:fieldId
     */
    public function deleteCustomField(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $fieldId = (int) $request->getParam('fieldId');
        
        $service = $this->getService();
        if (!$service->deleteCustomField($fieldId, $this->userEmail)) {
            return Response::error('Field not found', 404);
        }
        
        return Response::success(['deleted' => true]);
    }
}


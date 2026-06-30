<?php

namespace Webmail\Addons\Contacts\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\Contacts\Services\AddressBookService;

/**
 * AddressBookController
 * ---------------------------------------------------------------
 * Full per-user address book: address books + contacts CRUD, plus
 * VCF / CSV import and VCF export. The import endpoints are what the
 * Panel migration tooling drives (directly for the logged-in user, or
 * via MigrationController -> /api/internal/dav-import for any user).
 */
class AddressBookController extends BaseController
{
    private function service(): AddressBookService
    {
        return new AddressBookService($this->config);
    }

    // ---- Address books -------------------------------------------------

    public function listBooks(Request $request): Response
    {
        if ($e = $this->requireAuth($request)) return $e;
        $svc = $this->service();
        $books = $svc->listAddressBooks($this->userEmail);
        if (empty($books)) {
            // Lazily provision a default book so the UI always has a target.
            $svc->getOrCreateDefaultAddressBook($this->userEmail);
            $books = $svc->listAddressBooks($this->userEmail);
        }
        return Response::success(['address_books' => $books]);
    }

    public function createBook(Request $request): Response
    {
        if ($e = $this->requireAuth($request)) return $e;
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return Response::error('Name is required', 400);
        }
        $book = $this->service()->createAddressBook(
            $this->userEmail,
            $name,
            (string) $request->input('color', '#3b82f6'),
            (bool) $request->input('is_default', false),
            $request->input('description')
        );
        return Response::success(['address_book' => $book], 'Address book created');
    }

    public function updateBook(Request $request): Response
    {
        if ($e = $this->requireAuth($request)) return $e;
        $book = $this->service()->updateAddressBook($this->userEmail, (int) $request->getParam('id'), $request->input());
        return $book ? Response::success(['address_book' => $book], 'Updated') : Response::notFound('Address book not found');
    }

    public function deleteBook(Request $request): Response
    {
        if ($e = $this->requireAuth($request)) return $e;
        $ok = $this->service()->deleteAddressBook($this->userEmail, (int) $request->getParam('id'));
        return $ok ? Response::success(null, 'Deleted') : Response::notFound('Address book not found');
    }

    // ---- Contacts ------------------------------------------------------

    public function listContacts(Request $request): Response
    {
        if ($e = $this->requireAuth($request)) return $e;
        $bookId = $request->getQuery('book_id');
        $contacts = $this->service()->listContacts(
            $this->userEmail,
            $bookId ? (int) $bookId : null,
            $request->getQuery('search'),
            (int) ($request->getQuery('limit') ?? 1000),
            (int) ($request->getQuery('offset') ?? 0)
        );
        return Response::success(['contacts' => $contacts]);
    }

    public function getContact(Request $request): Response
    {
        if ($e = $this->requireAuth($request)) return $e;
        $contact = $this->service()->getContact($this->userEmail, (int) $request->getParam('id'));
        return $contact ? Response::success(['contact' => $contact]) : Response::notFound('Contact not found');
    }

    public function createContact(Request $request): Response
    {
        if ($e = $this->requireAuth($request)) return $e;
        $svc = $this->service();
        $bookId = (int) $request->input('book_id', 0);
        if (!$bookId) {
            $bookId = (int) $svc->getOrCreateDefaultAddressBook($this->userEmail)['id'];
        }
        $contact = $svc->createContact($this->userEmail, $bookId, $request->input());
        return $contact ? Response::success(['contact' => $contact], 'Contact created') : Response::error('Could not create contact', 400);
    }

    public function updateContact(Request $request): Response
    {
        if ($e = $this->requireAuth($request)) return $e;
        $contact = $this->service()->updateContact($this->userEmail, (int) $request->getParam('id'), $request->input());
        return $contact ? Response::success(['contact' => $contact], 'Updated') : Response::notFound('Contact not found');
    }

    public function deleteContact(Request $request): Response
    {
        if ($e = $this->requireAuth($request)) return $e;
        $ok = $this->service()->deleteContact($this->userEmail, (int) $request->getParam('id'));
        return $ok ? Response::success(null, 'Deleted') : Response::notFound('Contact not found');
    }

    // ---- Import / export ----------------------------------------------

    /**
     * Import contacts for the logged-in user. Accepts either a multipart
     * file upload (field "file") or a JSON body { data, format, book_id }.
     * Format is auto-detected (vCard vs CSV) when not given.
     */
    public function import(Request $request): Response
    {
        if ($e = $this->requireAuth($request)) return $e;
        $svc = $this->service();

        $bookId = (int) ($request->input('book_id', 0) ?: $request->getQuery('book_id', 0));
        if (!$bookId) {
            $bookId = (int) $svc->getOrCreateDefaultAddressBook($this->userEmail)['id'];
        }

        $data = null;
        $format = strtolower((string) $request->input('format', ''));
        $file = $request->getFile('file');
        if ($file && ($file['error'] ?? 1) === 0 && is_uploaded_file($file['tmp_name'])) {
            $data = file_get_contents($file['tmp_name']);
            if ($format === '' && str_ends_with(strtolower($file['name'] ?? ''), '.csv')) {
                $format = 'csv';
            }
        } else {
            $data = (string) $request->input('data', '');
        }

        if ($data === null || trim($data) === '') {
            return Response::error('No contact data provided', 400);
        }
        if ($format === '') {
            $format = stripos($data, 'BEGIN:VCARD') !== false ? 'vcf' : 'csv';
        }

        $result = $format === 'csv'
            ? $svc->importCsv($this->userEmail, $bookId, $data)
            : $svc->importVcf($this->userEmail, $bookId, $data);

        return Response::success($result, "Imported {$result['imported']} new, updated {$result['updated']}");
    }

    /**
     * Export the user's contacts as a single .vcf download.
     */
    public function export(Request $request): Response
    {
        if ($e = $this->requireAuth($request)) return $e;
        $bookId = $request->getQuery('book_id');
        $vcf = $this->service()->exportVcf($this->userEmail, $bookId ? (int) $bookId : null);
        return Response::raw($vcf, 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="contacts.vcf"',
        ]);
    }
}

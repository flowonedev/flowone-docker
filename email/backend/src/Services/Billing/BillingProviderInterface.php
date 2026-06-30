<?php

namespace Webmail\Services\Billing;

/**
 * BillingProviderInterface
 * 
 * Contract that all billing providers must implement.
 * Each provider (Billingo, Szamlazz.hu) handles the communication
 * with the external regulated invoicing platform.
 * 
 * Our CRM invoices are internal records. The legal invoice lives
 * on the external platform. We get the PDF back and store it.
 */
interface BillingProviderInterface
{
    /**
     * Get the provider identifier
     * @return string 'billingo' or 'szamlazz'
     */
    public function getProviderName(): string;

    /**
     * Test the connection / API key validity
     * @return array{success: bool, message: string, data?: array}
     */
    public function testConnection(): array;

    /**
     * Create an invoice on the external platform
     * 
     * @param array $invoiceData {
     *   client_name: string,
     *   client_tax_number?: string,
     *   client_address?: string,
     *   client_email?: string,
     *   invoice_number?: string,
     *   issue_date: string (Y-m-d),
     *   due_date: string (Y-m-d),
     *   currency: string,
     *   language: string (hu|en|de),
     *   payment_method: string,
     *   items: array<{description: string, quantity: float, unit: string, unit_price: float, tax_rate: float}>,
     *   notes?: string,
     *   electronic: bool (send electronically)
     * }
     * @return array{
     *   success: bool,
     *   external_id?: string,
     *   external_url?: string,
     *   external_pdf_url?: string,
     *   invoice_number?: string,
     *   message?: string,
     *   error?: string
     * }
     */
    public function createInvoice(array $invoiceData): array;

    /**
     * Download the invoice PDF from the external platform
     * 
     * @param string $externalId The invoice ID on the external platform
     * @return array{success: bool, pdf_content?: string, filename?: string, error?: string}
     */
    public function downloadPdf(string $externalId): array;

    /**
     * Get invoice status from the external platform
     * 
     * @param string $externalId
     * @return array{success: bool, status?: string, paid_amount?: float, error?: string}
     */
    public function getStatus(string $externalId): array;

    /**
     * Cancel/storno an invoice on the external platform
     * 
     * @param string $externalId
     * @return array{success: bool, message?: string, error?: string}
     */
    public function cancelInvoice(string $externalId): array;

    /**
     * Get available invoice blocks/number sequences (provider-specific)
     * 
     * @return array{success: bool, blocks?: array, error?: string}
     */
    public function getInvoiceBlocks(): array;
}


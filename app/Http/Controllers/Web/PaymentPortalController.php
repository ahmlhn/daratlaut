<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice as Invoice;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentPortalController extends Controller
{
    /**
     * Show public payment portal for an invoice
     * Token format: base64(invoice_id:invoice_number:hash)
     */
    public function show(string $token)
    {
        try {
            // Decode token
            $decoded = base64_decode($token);
            $parts = explode(':', $decoded);
            
            if (count($parts) < 3) {
                abort(404, 'Invalid payment link');
            }
            
            [$invoiceId, $invoiceNumber, $hash] = $parts;
            
            // Validate hash
            $expectedHash = substr(md5($invoiceId . $invoiceNumber . config('app.key')), 0, 8);
            if ($hash !== $expectedHash) {
                abort(404, 'Invalid payment link');
            }
            
            // Find invoice
            $invoice = Invoice::with(['customer', 'items'])->find($invoiceId);
            
            if (!$invoice || $invoice->invoice_no !== $invoiceNumber) {
                abort(404, 'Invoice not found');
            }
            
            return Inertia::render('PaymentPortal', [
                'invoiceId' => $invoiceId,
                'token' => $token,
            ]);
            
        } catch (\Exception $e) {
            abort(404, 'Invalid payment link');
        }
    }
    
    /**
     * Generate payment link token for an invoice
     */
    public static function generateToken(Invoice $invoice): string
    {
        $hash = substr(md5($invoice->id . $invoice->invoice_no . config('app.key')), 0, 8);
        return base64_encode("{$invoice->id}:{$invoice->invoice_no}:{$hash}");
    }
    
    /**
     * Get full payment URL for an invoice
     */
    public static function getPaymentUrl(Invoice $invoice): string
    {
        $token = self::generateToken($invoice);
        return url("/pay/{$token}");
    }
}

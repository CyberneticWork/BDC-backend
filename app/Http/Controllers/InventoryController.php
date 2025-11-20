<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Payment;
use App\Models\inventory_stock;
use App\Models\product;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{

    // INDEX - GET ALL INVENTORY RECORDS
    /**
     * GET /api/inventories
     * Return all inventory records with related data
     */
    public function index()
    {
        $inventory = Inventory::with([
            'creator:id,name',
            'approver:id,name',
            'items'
        ])->orderByDesc('id')->get();

        return response()->json([
            'data' => $inventory,
        ], 200);
    }

    // STORE - CREATE INVENTORY RECORD (USED BY BOTH GRN AND INVOICE ROUTES)
   /**
     * POST /api/inventories (from apiResource)
     * POST /api/grn (specific GRN route)
     * POST /api/invoices (specific Invoice route)
     * Creates GRN or Invoice based on the endpoint called
     */
    public function store(Request $request)
    {
        // SALES ORDER INTEGRATION: the /salesOrder endpoint also lands here so
        // that sales orders share the same persistence + stock deduction logic
        // as invoices/GRNs. Keep this block aligned with the frontend payload.
        // DETERMINE DOCUMENT TYPE BASED ON ROUTE
        $documentType = 'grn'; // Default for apiResource inventories

        if (str_contains($request->url(), 'salesOrder')) {
            $documentType = 'sales_order';
        } else if (str_contains($request->url(), 'invoices')) {
            $documentType = 'invoice';
        } else if (str_contains($request->url(), 'grn')) {
            $documentType = 'grn';
        } else {
            // For apiResource inventories endpoint, check type parameter
            $documentType = strtolower($request->input('type', 'grn'));
        }


        // Reference number handling
        $referNumber = $request->input('referNumber') ?? $request->input('refNumber');

        // Discount value handling
        $discountValue = $request->input('discountValue');
        if ($discountValue === null) {
            $discountValue = $request->input('discount', 0);
        }

        // Total amount handling
        $amount = $request->input('amount');
        if ($amount === null) {
            $amount = $request->input('total', $request->input('totalAmount', 0));
        }

        // Paid value handling
        $paid_value = $request->input('paid_value');
        if ($paid_value === null) {
            $paid_value = $request->input('paid', 0);
        }

        // Quantity handling
        $quantity = $request->input('quantity');
        if ($quantity === null) {
            $quantity = $request->input('qty');
        }

        $unitPrice = $request->input('unitPrice');

        // ITEM LINE DETECTION - Try multiple possible keys for line items
        $candidateLineGroups = [
            $request->input('items'),
            $request->input('inventory_products'),
            $request->input('inventoryProducts'),
            $request->input('products'),
            $request->input('lines'),
        ];

        $items = [];
        foreach ($candidateLineGroups as $group) {
            if (is_array($group) && count($group) > 0) {
                $items = $group;
                break;
            }
        }

        // CALCULATE DERIVED VALUES FROM LINE ITEMS
        if (!empty($items)) {
            // Calculate total quantity from line items if not provided at root
            if ($quantity === null) {
                $quantity = collect($items)->sum(function ($it) {
                    return (int)($it['quantity'] ?? $it['qty'] ?? data_get($it, 'pivot.quantity', 0));
                });
            }

            // Get unit price from first item if not provided at root
            if ($unitPrice === null) {
                $firstItem = $items[0];
                $unitPrice = (float)($firstItem['unitPrice'] ?? $firstItem['cost'] ?? data_get($firstItem, 'pivot.cost', 0));
            }

            // Calculate total amount from line items if not provided or invalid
            if ($amount === null || (float)$amount <= 0) {
                $amount = collect($items)->sum(function ($it) {
                    $q = (float)($it['quantity'] ?? $it['qty'] ?? data_get($it, 'pivot.quantity', 0));
                    $u = (float)($it['unitPrice'] ?? $it['cost'] ?? data_get($it, 'pivot.cost', 0));
                    $lineAmount = $it['amount'] ?? $it['total'] ?? data_get($it, 'pivot.amount');

                    return $lineAmount !== null ? (float)$lineAmount : $q * $u;
                });
            }
        }

        // FINAL DATA TYPE CASTING AND VALIDATION
        $referNumber = $referNumber ? (string)$referNumber : null;
        $discountValue = (float)$discountValue;
        $quantity = (int)($quantity ?? 0);
        $unitPrice = (float)($unitPrice ?? 0);
        $amount = (float)($amount ?? 0);
        $paid_value = (float)($paid_value ?? 0);

        // AUTHENTICATION CHECK - Ensure we have a valid creator
        $creatorId = optional($request->user())->id ?? $request->input('created_by');
        if (!$creatorId) {
            return response()->json([
                'message' => 'Unauthenticated: provide a valid token or created_by user id.'
            ], 401);
        }

        // PREPARE LINE ITEM PAYLOADS
        $linePayloads = [];
        if (!empty($items)) {
            foreach ($items as $line) {
                if (!is_array($line)) {
                    continue;
                }

                // Extract product ID from multiple possible keys
                $productId = $line['product_id']
                    ?? $line['productId']
                    ?? $line['id']
                    ?? data_get($line, 'product.id');

                $lineQty = (int)($line['quantity'] ?? $line['qty'] ?? data_get($line, 'pivot.quantity', 0));
                if ($lineQty < 0) {
                    $lineQty = 0;
                }

                $lineCost = $line['unitPrice'] ?? $line['cost'] ?? data_get($line, 'pivot.cost', 0);
                $lineMinPrice = $line['min_price'] ?? $line['minPrice'] ?? data_get($line, 'pivot.min_price', 0);
                $lineMrp = $line['mrp'] ?? data_get($line, 'pivot.mrp', 0);
                $lineAmount = $line['amount'] ?? $line['total'] ?? data_get($line, 'pivot.amount');

                // CALCULATE LINE AMOUNT BASED ON DOCUMENT TYPE
                if ($lineAmount === null) {
                    if (in_array($documentType, ['invoice', 'sales_order'], true)) {
                        // For invoices: (unitPrice * quantity) - (discount * quantity)
                        $lineDiscount = (float)($line['discount'] ?? 0);
                        $lineAmount = ($lineCost * $lineQty) - ($lineDiscount * $lineQty);
                    } else {
                        // For GRN: simple quantity * cost
                        $lineAmount = $lineQty * (float)$lineCost;
                    }
                }

                // Only add valid line items with product and quantity
                if ($productId && $lineQty > 0) {
                    $linePayloads[] = [
                        'product_id' => (int)$productId,
                        'quantity' => $lineQty,
                        'cost' => (float)$lineCost,
                        'min_price' => (float)$lineMinPrice,
                        'mrp' => (float)$lineMrp,
                        'amount' => (float)$lineAmount,
                        'created_by' => $creatorId,
                    ];
                }
            }
        }

        // EXTRACT STOCK LINES FOR INVENTORY ADJUSTMENTS
        $stockLines = $this->extractStockLines($items, $linePayloads);

        // FALLBACK: Handle single product case (legacy support)
        if (empty($linePayloads)) {
            $rootProductId = $request->input('product_id') ?? $request->input('productId') ?? $request->input('product');
            if ($rootProductId && $quantity > 0) {
                $linePayloads[] = [
                    'product_id' => (int)$rootProductId,
                    'quantity' => $quantity,
                    'cost' => $unitPrice,
                    'min_price' => (float)($request->input('min_price') ?? $request->input('minPrice') ?? 0),
                    'mrp' => (float)($request->input('mrp') ?? 0),
                    'amount' => (float)($request->input('lineAmount') ?? ($quantity * $unitPrice)),
                    'created_by' => $creatorId,
                ];
            }
        }

        // VALIDATION: Ensure we have line items
        if (empty($linePayloads)) {
            return response()->json([
                'message' => 'No valid inventory item lines were provided.',
            ], 422);
        }

        // VALIDATION: Check if all products exist
        $productIds = array_unique(array_column($linePayloads, 'product_id'));
        if (!empty($productIds)) {
            $existingIds = product::whereIn('id', $productIds)->pluck('id')->all();
            $missingIds = array_diff($productIds, $existingIds);
            if (!empty($missingIds)) {
                return response()->json([
                    'message' => 'One or more products referenced do not exist.',
                    'missing_product_ids' => array_values($missingIds),
                ], 422);
            }
        }

        $center_id = $request->input('center_id', $request->input('centerId'));
        $supplier_id = null;
        $customer_id = null;
        $from_center = null;
        $to_center = null;

        // DOCUMENT TYPE SPECIFIC VALIDATIONS AND DATA PREPARATION
        if ($documentType === 'invoice') {
            // INVOICE SPECIFIC VALIDATION: Customer is required
            $customer_id = $request->input('customer_id', $request->input('customerId'));
            $customerName = $request->input('customer') ?? $request->input('customerName');

            if (!$customer_id) {
                if (!$customerName) {
                    return response()->json([
                        'message' => 'Customer is required for invoices.'
                    ], 422);
                }

                $customer = Customer::where('name', $customerName)->first();
                if (!$customer) {
                    return response()->json([
                        'message' => "Customer '{$customerName}' not found."
                    ], 422);
                }
                $customer_id = $customer->id;
            } else {
                $customer = Customer::find($customer_id);
                if (!$customer) {
                    return response()->json([
                        'message' => "Customer ID {$customer_id} not found."
                    ], 422);
                }
            }

            $supplier_id = null;
            $from_center = null;
            $to_center = null;

        } elseif ($documentType === 'sales_order') {
            $customer_id = $request->input('customer_id', $request->input('customerId'));
            $customerName = $request->input('customer') ?? $request->input('customerName');

            if (!$customer_id) {
                if (!$customerName) {
                    return response()->json([
                        'message' => 'Customer is required for sales orders.'
                    ], 422);
                }

                $customer = Customer::where('name', $customerName)->first();
                if (!$customer) {
                    return response()->json([
                        'message' => "Customer '{$customerName}' not found."
                    ], 422);
                }
                $customer_id = $customer->id;
            } else {
                $customer = Customer::find($customer_id);
                if (!$customer) {
                    return response()->json([
                        'message' => "Customer ID {$customer_id} not found."
                    ], 422);
                }
            }

            if (!$center_id) {
                return response()->json([
                    'message' => 'Center is required for sales orders.'
                ], 422);
            }

            $supplier_id = null;
            $from_center = null;
            $to_center = null;

        } else {
            // GRN SPECIFIC: Handle supplier and center relationships
            $customer_id = $request->input('customer_id', $request->input('customerId'));
            $supplier_id = $request->input('supplier_id', $request->input('supplierId'));
            $from_center = $request->input('from_center', $request->input('fromCenter'));
            $to_center = $request->input('to_center', $request->input('toCenter'));

            // Find customer by ID if provided, otherwise null
            if ($customer_id) {
                $customer = Customer::find($customer_id);
                if (!$customer) {
                    return response()->json([
                        'message' => "Customer ID {$customer_id} not found."
                    ], 422);
                }
            }
        }

        // Determine stock center (GRN adds, Invoice deducts)
        $stockCenterId = null;
        if ($documentType === 'grn') {
            $stockCenterId = $request->input('stock_center_id')
                ?? $to_center
                ?? $center_id
                ?? $from_center;
        } else {
            $stockCenterId = $request->input('stock_center_id')
                ?? $center_id
                ?? $from_center
                ?? $request->input('center');
        }

        // STATUS - Allow frontend-supplied status while enforcing the enum on save
        $allowedStatuses = ['pending', 'reject', 'completed'];
        $statusInput = $request->input('status');
        $status = 'pending';
        if ($statusInput !== null) {
            $normalizedStatus = strtolower(trim($statusInput));
            if (!in_array($normalizedStatus, $allowedStatuses, true)) {
                return response()->json([
                    'message' => "Invalid status value. Allowed: " . implode(', ', $allowedStatuses) . ".",
                ], 422);
            }
            $status = $normalizedStatus;
        }

        // PAYMENT DATA EXTRACTION - Support nested payment object and root level fields
        $paymentInput = $request->input('payment', []);
        $paymentAmount = isset($paymentInput['amount']) ? (float)$paymentInput['amount'] : $paid_value;

        // Payment mode and details with multiple key support
        $paymentMode = $paymentInput['mode'] ?? $request->input('mode') ?? null;
        $paymentNote = $paymentInput['note'] ?? $request->input('note') ?? null;
        $bankName = $paymentInput['bankName'] ?? $paymentInput['bank_name'] ?? $request->input('bankName') ?? $request->input('bank_name');
        $chequeNo = $paymentInput['chequeNo'] ?? $paymentInput['cheque_no'] ?? $request->input('chequeNo') ?? $request->input('cheque_no');
        $chequeDate = $paymentInput['chequeDate'] ?? $paymentInput['cheque_date'] ?? $request->input('chequeDate') ?? $request->input('cheque_date');

        // INVOICE SPECIFIC PAYMENT FIELDS
        $referenceNo = $paymentInput['referenceNo'] ?? $paymentInput['reference_no'] ?? null;
        $transferDate = $paymentInput['transferDate'] ?? $paymentInput['transfer_date'] ?? null;

        // DATABASE TRANSACTION - Atomic persistence of inventory, items, and payment
        $result = DB::transaction(function () use (
            $documentType,
            $request,
            $discountValue,
            $amount,
            $paid_value,
            $referNumber,
            $center_id,
            $supplier_id,
            $customer_id,
            $from_center,
            $to_center,
            $status,
            $creatorId,
            $linePayloads,
            $stockLines,
            $stockCenterId,
            $paymentAmount,
            $paymentMode,
            $paymentNote,
            $bankName,
            $chequeNo,
            $chequeDate,
            $referenceNo,
            $transferDate
        ) {
            // VOUCHER NUMBER GENERATION BASED ON DOCUMENT TYPE
            $voucherNumber = $request->input('voucherNumber')
                ?? $request->input('voucher_number')
                ?? $request->input('orderNumber')
                ?? $request->input('id'); // Frontend may send pre-generated ID

            if (!$voucherNumber) {
                $voucherNumber = $this->buildNextVoucherResponse($documentType, true)['next'];
            }

            // CREATE INVENTORY RECORD
            $recordData = [
                'voucherNumber' => $voucherNumber,
                'amount' => $amount,
                'paid_value' => $paid_value,
                'discountValue' => $discountValue,
                'referNumber' => $referNumber,
                'center_id' => $center_id,
                'supplier_id' => $supplier_id,
                'customer_id' => $customer_id,
                'from_center' => $from_center,
                'to_center' => $to_center,
                'status' => $status,
                'created_by' => $creatorId,
                'approved_by' => null,
            ];

            $record = Inventory::create($recordData);

            // CREATE LINE ITEMS
            $createdItems = [];
            if (!empty($linePayloads)) {
                $createdItems = $record->items()->createMany($linePayloads);
            }

            // CREATE PAYMENT RECORD IF PAYMENT AMOUNT > 0
            $payment = null;
            if ($paymentAmount && (float)$paymentAmount > 0) {
                // Build comprehensive payment note for invoices
                $finalNote = $paymentNote;
                if ($documentType === 'invoice') {
                    $noteDetails = [];
                    if ($paymentNote) $noteDetails[] = $paymentNote;
                    if ($referenceNo) $noteDetails[] = "Ref: {$referenceNo}";
                    if ($transferDate) $noteDetails[] = "Date: {$transferDate}";

                    $finalNote = !empty($noteDetails) ? implode(' | ', $noteDetails) : $paymentNote;
                }

                $payment = Payment::create([
                    'inventory_id' => $record->id,
                    'amount' => (float)$paymentAmount,
                    'mode' => $paymentMode,
                    'note' => $finalNote,
                    'bank_name' => $bankName,
                    'cheque_no' => $chequeNo,
                    'cheque_date' => $chequeDate,
                    'created_by' => $creatorId,
                ]);
            }

            // UPDATE INVENTORY STOCK BASED ON DOCUMENT TYPE -----------------------------*
            if (!empty($stockLines) && $stockCenterId) {
                if ($documentType === 'grn') {
                    $this->applyInventoryStockAdjustments($stockLines, (int)$stockCenterId, (int)$creatorId, 'add');
                } elseif (in_array($documentType, ['invoice', 'sales_order'], true)) {
                    $this->applyInventoryStockAdjustments($stockLines, (int)$stockCenterId, (int)$creatorId, 'subtract');
                }
            }

            return [
                'record' => $record,
                'items' => $createdItems,
                'payment' => $payment,
                'document_type' => $documentType,
            ];
        });

        $record = $result['record'];
        $payment = $result['payment'];
        $documentType = $result['document_type'];

        // LOAD RELATIONSHIPS BASED ON DOCUMENT TYPE
        $relationships = ['creator:id,name', 'approver:id,name', 'items.product'];
        if (in_array($documentType, ['invoice', 'sales_order'], true)) {
            $relationships[] = 'customer';
        }

        // SUCCESS RESPONSE
        $messageMap = [
            'grn' => 'GRN saved successfully',
            'invoice' => 'Invoice saved successfully',
            'sales_order' => 'Sales order saved successfully',
        ];
        $message = $messageMap[$documentType] ?? 'Inventory saved successfully';

        return response()->json([
            'message' => $message,
            'data' => tap($record->load($relationships), function ($loaded) use ($payment) {
                if ($payment) {
                    $loaded->payment = $payment;
                }
            }),
            'document_type' => $documentType,
        ], 201);
    }

    // ======================================================================
    // SHOW - GET SINGLE INVENTORY RECORD
    // ======================================================================
    /**
     * GET /api/inventories/{id}
     * Return a single inventory record with related data
     */
    public function show($id)
    {
        $record = Inventory::with([
            'creator:id,name',
            'approver:id,name',
            'items'
        ])->find($id);

        if (!$record) {
            return response()->json([
                'message' => 'Inventory record not found.'
            ], 404);
        }

        return response()->json([
            'data' => $record,
        ], 200);
    }

    // ======================================================================
    // UPDATE - MODIFY EXISTING INVENTORY RECORD
    // ======================================================================
    /**
     * PUT/PATCH /api/inventories/{id}
     * Update an existing inventory record with flexible input handling
     */
    public function update(Request $request, $id)
    {
        $record = Inventory::find($id);
        if (!$record) {
            return response()->json([
                'message' => 'Inventory record not found.'
            ], 404);
        }


        $voucherNumber = $request->input('voucherNumber')
            ?? $request->input('grnNumber')
            ?? $request->input('id');

        $referNumber = $request->input('referNumber')
            ?? $request->input('refNumber');

        $discountValue = $request->input('discountValue');
        if ($discountValue === null) {
            $discountValue = $request->input('discount');
        }

        $amount = $request->input('amount');
        if ($amount === null) {
            $amount = $request->input('total', $request->input('totalAmount'));
        }

        $paid_value = $request->input('paid_value');
        if ($paid_value === null) {
            $paid_value = $request->input('paid');
        }


        $items = $request->input('items', []);
        if (is_array($items) && count($items) > 0) {
            if ($amount === null || (float)$amount <= 0) {
                $amount = collect($items)->sum(function ($it) {
                    $q = (float)($it['quantity'] ?? 0);
                    $u = (float)($it['unitPrice'] ?? 0);
                    return $q * $u;
                });
            }
        }


        $status = $request->input('status');
        $allowedStatus = ['pending', 'reject', 'completed'];
        if ($status !== null && !in_array($status, $allowedStatus, true)) {
            return response()->json([
                'message' => 'Invalid status value. Allowed: pending, reject, completed.'
            ], 422);
        }

        // FOREIGN KEY FIELDS
        $center_id   = $request->input('center_id');
        $supplier_id = $request->input('supplier_id');
        $customer_id = $request->input('customer_id');
        $from_center = $request->input('from_center');
        $to_center   = $request->input('to_center');

        // BUILD UPDATE PAYLOAD - Only include provided fields
        $payload = [];
        if ($voucherNumber !== null) $payload['voucherNumber'] = (string)$voucherNumber;
        if ($amount !== null)        $payload['amount'] = (float)$amount;
        if ($paid_value !== null)    $payload['paid_value'] = (float)$paid_value;
        if ($discountValue !== null) $payload['discountValue'] = (float)$discountValue;
        if ($referNumber !== null)   $payload['referNumber'] = $referNumber ? (string)$referNumber : null;
        if ($status !== null)        $payload['status'] = $status;
        if ($request->exists('center_id'))     $payload['center_id'] = $center_id;
        if ($request->exists('supplier_id'))   $payload['supplier_id'] = $supplier_id;
        if ($request->exists('customer_id'))   $payload['customer_id'] = $customer_id;
        if ($request->exists('from_center'))   $payload['from_center'] = $from_center;
        if ($request->exists('to_center'))     $payload['to_center'] = $to_center;

        // VALIDATION: Ensure at least one field is being updated
        if (empty($payload)) {
            return response()->json([
                'message' => 'No updatable fields provided.'
            ], 422);
        }

        // PERFORM UPDATE
        $record->update($payload);

        // LOAD FRESH DATA WITH RELATIONSHIPS
        $record->load(['creator:id,name', 'approver:id,name']);

        return response()->json([
            'message' => 'Inventory updated successfully',
            'data' => $record,
        ], 200);
    }

    // ======================================================================
    // DESTROY - SOFT DELETE INVENTORY RECORD
    // ======================================================================
    /**
     * DELETE /api/inventories/{id}
     * Soft delete an inventory record
     */
    public function destroy($id)
    {
        $record = Inventory::find($id);
        if (!$record) {
            return response()->json([
                'message' => 'Inventory record not found.'
            ], 404);
        }

        $record->delete();

        return response()->json([
            'message' => 'Inventory deleted successfully'
        ], 200);
    }

    // ======================================================================
    // NEXT GRN - PREVIEW NEXT GRN NUMBER
    // ======================================================================
    /**
     * GET /api/grn/next
     * Preview next GRN number without creating a record
     */
    public function nextGrn()
    {
        return response()->json([
            'data' => $this->buildNextVoucherResponse('grn')
        ], 200);
    }

    // ======================================================================
    // NEXT INVOICE - PREVIEW NEXT INVOICE NUMBER
    // ======================================================================
    /**
     * GET /api/invoices/next
     * Preview next Invoice number without creating a record
     */
    public function nextInv()
    {
        return response()->json([
            'data' => $this->buildNextVoucherResponse('invoice')
        ], 200);
    }


       // ======================================================================
    // NEXT SALES ORDER - PREVIEW NEXT SALES ORDER NUMBER
    // ======================================================================
    /**
     * GET /api/salesOrder/next
     * Preview next SALES ORDER number without creating a record
     */
    public function nextSalesOrder()
    {
        return response()->json([
            'data' => $this->buildNextVoucherResponse('sales_order')
        ], 200);
    }


    // ======================================================================
    // STORE SALES ORDER - ROUTE NORMALIZER
    // ======================================================================
    /**
     * POST /api/salesOrder
     * Normalize incoming payload and delegate to store()
     */
    public function storeSalesOrder(Request $request)
    {
        $normalizations = [
            'type' => 'sales_order',
        ];

        if (!$request->has('voucherNumber') && !$request->has('voucher_number')) {
            $orderNumber = $request->input('orderNumber');
            if ($orderNumber) {
                $normalizations['voucherNumber'] = $orderNumber;
            }
        }

        if (!$request->has('center_id') && $request->has('centerId')) {
            $normalizations['center_id'] = $request->input('centerId');
        }

        if (!$request->has('customer_id') && $request->has('customerId')) {
            $normalizations['customer_id'] = $request->input('customerId');
        }

        if (!$request->has('referNumber') && $request->has('refNumber')) {
            $normalizations['referNumber'] = $request->input('refNumber');
        }

        if (!$request->has('discountValue') && $request->has('discountTotal')) {
            $normalizations['discountValue'] = $request->input('discountTotal');
        }

        if (!$request->has('amount') && $request->has('totalAmount')) {
            $normalizations['amount'] = $request->input('totalAmount');
        }

        if (!$request->has('paid_value') && $request->has('paidValue')) {
            $normalizations['paid_value'] = $request->input('paidValue');
        }

        if (!$request->has('created_by') && ($request->has('created_by_id') || $request->has('createdById'))) {
            $normalizations['created_by'] = $request->input('created_by_id', $request->input('createdById'));
        }

        if (!empty($normalizations)) {
            $request->merge($normalizations);
        }

        return $this->store($request);
    }



    // STORE INVOICE - LEGACY METHOD (NOW HANDLED BY STORE METHOD)
    /**
     * POST /api/invoices
     * Legacy method - now handled by the main store() method
     * Kept for backward compatibility
     */
    public function storeInvoice(Request $request)
    {
        // Simply call the main store method
        // The URL detection in store() will handle it as an invoice
        return $this->store($request);
    }


    // PRIVATE HELPER METHODS
    /**
     * Extract stock lines (product, quantity, batch) from raw request items
     */
    private function getVoucherPrefix(string $documentType, string $year): string
    {
        return match ($documentType) {
            'invoice' => "INV-{$year}-",
            'sales_order' => "SO-{$year}-",
            default => "GRN-{$year}-",
        };
    }

    private function buildNextVoucherResponse(string $documentType, bool $lockForUpdate = false): array
    {
        $year = date('y');
        $prefix = $this->getVoucherPrefix($documentType, $year);
        $query = Inventory::where('voucherNumber', 'like', $prefix . '%');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $latest = $query->orderBy('voucherNumber', 'desc')->value('voucherNumber');
        $maxNum = $latest ? (int)substr($latest, strlen($prefix)) : 0;
        $nextNum = $maxNum + 1;

        return [
            'next' => $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT),
            'year' => $year,
            'sequence' => $nextNum,
        ];
    }

    private function extractStockLines($rawItems, array $linePayloads): array
    {
        $stockLines = [];

        if (is_array($rawItems) && !empty($rawItems)) {
            foreach ($rawItems as $line) {
                if (!is_array($line)) {
                    continue;
                }

                $productId = $line['product_id']
                    ?? $line['productId']
                    ?? $line['id']
                    ?? data_get($line, 'product.id');
                if (!$productId) {
                    continue;
                }

                // Handle batch collections
                $batchCollections = $line['batches'] ?? $line['batchEntries'] ?? null;
                if (is_array($batchCollections) && !empty($batchCollections)) {
                    foreach ($batchCollections as $batchLine) {
                        $qty = (int)($batchLine['quantity'] ?? $batchLine['qty'] ?? 0);
                        if ($qty <= 0) {
                            continue;
                        }
                        $batchNumber = $batchLine['batch_number']
                            ?? $batchLine['batchNumber']
                            ?? $batchLine['batch']
                            ?? null;
                        $stockLines[] = [
                            'product_id' => (int)$productId,
                            'quantity' => $qty,
                            'batch_number' => $this->normalizeBatchNumber($batchNumber),
                        ];
                    }
                    continue;
                }

                // Handle simple line items
                $qty = (int)($line['quantity'] ?? $line['qty'] ?? data_get($line, 'pivot.quantity', 0));
                if ($qty <= 0) {
                    continue;
                }

                $batchNumber = $line['batch_number']
                    ?? $line['batchNumber']
                    ?? $line['batch']
                    ?? null;

                $stockLines[] = [
                    'product_id' => (int)$productId,
                    'quantity' => $qty,
                    'batch_number' => $this->normalizeBatchNumber($batchNumber),
                ];
            }
        }

        // Fallback to line payloads
        if (empty($stockLines) && !empty($linePayloads)) {
            foreach ($linePayloads as $line) {
                $stockLines[] = [
                    'product_id' => (int)$line['product_id'],
                    'quantity' => (int)$line['quantity'],
                    'batch_number' => null,
                ];
            }
        }

        return $stockLines;
    }

    /**
     * Merge incoming quantities into inventory_stocks per product/batch/center
     */
    private function applyInventoryStockAdjustments(array $stockLines, ?int $centerId, int $userId, string $mode = 'add'): void
    {
        if (!$centerId || empty($stockLines)) {
            return;
        }

        $mode = strtolower($mode) === 'subtract' ? 'subtract' : 'add';

        $aggregated = [];
        foreach ($stockLines as $line) {
            $productId = (int)($line['product_id'] ?? 0);
            $qty = (int)($line['quantity'] ?? 0);
            $batchNumber = array_key_exists('batch_number', $line)
                ? $this->normalizeBatchNumber($line['batch_number'])
                : null;

            if ($productId <= 0 || $qty <= 0) {
                continue;
            }

            $batchKey = $batchNumber ?? '';
            $key = $productId . '|' . $batchKey;

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'product_id' => $productId,
                    'quantity' => 0,
                    'batch_number' => $batchNumber,
                ];
            }

            $aggregated[$key]['quantity'] += $qty;
        }

        foreach ($aggregated as $payload) {
            if ($mode === 'subtract') {
                $this->deductInventoryStockRow(
                    $payload['product_id'],
                    $centerId,
                    $payload['batch_number'],
                    $payload['quantity'],
                    $userId
                );
            } else {
                $this->upsertInventoryStockRow(
                    $payload['product_id'],
                    $centerId,
                    $payload['batch_number'],
                    $payload['quantity'],
                    $userId
                );
            }
        }
    }

    private function deductInventoryStockRow(int $productId, int $centerId, ?string $batchNumber, int $quantityDelta, int $userId): void
    {
        if ($quantityDelta <= 0) {
            return;
        }

        $query = inventory_stock::where('product_id', $productId)
            ->where('center_id', $centerId);

        if ($batchNumber === null) {
            $query->whereNull('batch_number');
        } else {
            $query->where('batch_number', $batchNumber);
        }

        $stock = $query->lockForUpdate()->first();

        if (!$stock || ($stock->quantity ?? 0) < $quantityDelta) {
            $batchText = $batchNumber ? " (batch {$batchNumber})" : '';
            throw ValidationException::withMessages([
                'inventory_stock' => "Insufficient stock for product {$productId}{$batchText} at center {$centerId}.",
            ]);
        }

        $stock->quantity = ($stock->quantity ?? 0) - $quantityDelta;
        $stock->updated_by = $userId;
        $stock->save();
    }

    /**
     * Normalize batch number
     */
    private function normalizeBatchNumber($batchNumber): ?string
    {
        if ($batchNumber === null) {
            return null;
        }

        $trimmed = trim((string)$batchNumber);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Upsert inventory stock row
     */
    private function upsertInventoryStockRow(int $productId, int $centerId, ?string $batchNumber, int $quantityDelta, int $userId): void
    {
        if ($quantityDelta <= 0) {
            return;
        }

        $query = inventory_stock::where('product_id', $productId)
            ->where('center_id', $centerId);

        if ($batchNumber === null) {
            $query->whereNull('batch_number');
        } else {
            $query->where('batch_number', $batchNumber);
        }

        $stock = $query->lockForUpdate()->first();

        if ($stock) {
            $stock->quantity = ($stock->quantity ?? 0) + $quantityDelta;
            $stock->updated_by = $userId;
            $stock->save();
            return;
        }

        inventory_stock::create([
            'product_id' => $productId,
            'center_id' => $centerId,
            'batch_number' => $batchNumber,
            'quantity' => $quantityDelta,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }
}

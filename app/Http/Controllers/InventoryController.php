<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Payment;
use App\Models\inventory_stock;
use App\Models\product;
use App\Models\Customer;
use App\Models\centers;
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
        } elseif (str_contains($request->url(), 'salesreturn') || str_contains($request->url(), 'salesReturn') || str_contains($request->url(), 'sales-return')) {
            $documentType = 'sales_return';
        } else if (str_contains($request->url(), 'invoices')) {
            $documentType = 'invoice';
        } else if (str_contains($request->url(), 'grn')) {
            $documentType = 'grn';
        } else if (str_contains($request->url(), 'transfer') || str_contains($request->url(), 'stockTransfer') || str_contains($request->url(), 'stock-transfer')) {
            $documentType = 'stock_transfer';
        } else {
            // For apiResource inventories endpoint, check type parameter
            $documentType = strtolower($request->input('type', 'grn'));
        }


        // Reference number handling
        $referNumber = $request->input('referNumber') ?? $request->input('refNumber');

        // Reference voucher tracking (new columns)
        $referVoucherNumberInput = $request->input('refervoucherNumber')
            ?? $request->input('referVoucherNumber')
            ?? $request->input('refVoucherNumber')
            ?? $request->input('refer_voucher_number')
            ?? $request->input('ref_voucher_number');

        $isRefInput = null;
        if ($request->exists('is_ref')) {
            $isRefInput = filter_var($request->input('is_ref'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } elseif ($request->exists('isRef')) {
            $isRefInput = filter_var($request->input('isRef'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

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
        $referVoucherNumber = $referVoucherNumberInput ? (string)$referVoucherNumberInput : null;
        $isRef = (bool)($isRefInput ?? false);

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

        } elseif ($documentType === 'sales_return') {
            // Sales Return: customer is required, center is required (accept name or id)
            $customer_id = $request->input('customer_id', $request->input('customerId'));
            $customerName = $request->input('customer') ?? $request->input('customerName');

            if (!$customer_id) {
                if (!$customerName) {
                    return response()->json([
                        'message' => 'Customer is required for sales returns.'
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

            // Center: allow name or id
            $center_id = $request->input('center_id', $request->input('centerId'));
            if (!$center_id && $request->filled('center')) {
                $centerName = $request->input('center');
                $centerModel = centers::where('name', $centerName)->first();
                if ($centerModel) {
                    $center_id = $centerModel->id;
                } else {
                    return response()->json([
                        'message' => "Center '{$centerName}' not found."
                    ], 422);
                }
            }

            if (!$center_id) {
                return response()->json([
                    'message' => 'Center is required for sales returns.'
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
            $referVoucherNumber,
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
            $transferDate,
            $isRef
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
                    'refervoucherNumber' => $referVoucherNumber,
                'center_id' => $center_id,
                'supplier_id' => $supplier_id,
                'customer_id' => $customer_id,
                'from_center' => $from_center,
                'to_center' => $to_center,
                    'is_ref' => $isRef,
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
            // Note: Sales Orders should not modify `inventory_stock` quantities.
            if (!empty($stockLines) && $stockCenterId) {
                if ($documentType === 'grn' || $documentType === 'sales_return') {
                    // GRN and Sales Return both add to stock
                    $this->applyInventoryStockAdjustments($stockLines, (int)$stockCenterId, (int)$creatorId, 'add');
                } elseif ($documentType === 'invoice') {
                    // Only invoices subtract from stock; sales orders do not affect stock levels
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
            'sales_return' => 'Sales return saved successfully',
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

        $refVoucherNumber = null;
        $refVoucherNumberProvided = false;
        foreach ([
            'refervoucherNumber',
            'referVoucherNumber',
            'refVoucherNumber',
            'refer_voucher_number',
            'ref_voucher_number',
        ] as $key) {
            if ($request->exists($key)) {
                $refVoucherNumberProvided = true;
                $refVoucherNumber = $request->input($key);
                break;
            }
        }

        $isRefProvided = false;
        $isRefValue = null;
        foreach (['is_ref', 'isRef'] as $key) {
            if ($request->exists($key)) {
                $isRefProvided = true;
                $isRefValue = filter_var($request->input($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                break;
            }
        }

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
        if ($refVoucherNumberProvided) {
            $payload['refervoucherNumber'] = $refVoucherNumber ? (string)$refVoucherNumber : null;
        }
        if ($status !== null)        $payload['status'] = $status;
        if ($isRefProvided)          $payload['is_ref'] = (bool)($isRefValue ?? false);
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
    // NEXT SALES RETURN - PREVIEW NEXT SALES RETURN NUMBER
    // ======================================================================
    /**
     * GET /api/salesreturn/next
     * Preview next Sales Return number without creating a record
     */
    public function nextSalesReturn()
    {
        return response()->json([
            'data' => $this->buildNextVoucherResponse('sales_return')
        ], 200);
    }


    // ======================================================================
    // NEXT STOCK TRANSFER - PREVIEW NEXT STOCK TRANSFER NUMBER
    // ======================================================================
    /**
     * GET /api/stock-transfer/next
     * Preview next Stock Transfer number without creating a record
     */
    public function nextStockTransfer()
    {
        return response()->json([
            'data' => $this->buildNextVoucherResponse('stock_transfer')
        ], 200);
    }


    // ======================================================================
    // NEXT PURCHASE ORDER - PREVIEW NEXT PURCHASE ORDER NUMBER
    // ======================================================================
    /**
     * GET /api/purchase-order/next
     * Preview next Purchase Order number without creating a record
     */
    public function nextPurchaseOrder()
    {
        return response()->json([
            'data' => $this->buildNextVoucherResponse('purchase_order')
        ], 200);
    }


    // ======================================================================
    // LIST SALES ORDERS - GET ALL SALES ORDERS
    // ======================================================================
    /**
     * GET /api/salesOrder
     * Return all sales order inventory records with related data
     */
    public function listSalesOrders(Request $request)
    {
        $allowedStatuses = ['pending', 'reject', 'completed'];

        $query = Inventory::with([
            'creator:id,name',
            'approver:id,name',
            'customer:id,name',
            'items.product',
            'latestPayment',
        ])->where('voucherNumber', 'like', 'SO-%');

        $statusFilter = $request->input('status');
        if ($statusFilter !== null) {
            $normalizedStatus = strtolower(trim($statusFilter));
            if (!in_array($normalizedStatus, $allowedStatuses, true)) {
                return response()->json([
                    'message' => 'Invalid status filter. Allowed: ' . implode(', ', $allowedStatuses) . '.',
                ], 422);
            }
            $query->where('status', $normalizedStatus);
        }

        if ($request->filled('center_id')) {
            $query->where('center_id', (int)$request->input('center_id'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int)$request->input('customer_id'));
        }

        $records = $query->orderByDesc('id')->get();

        // Collect center_ids and product_ids to fetch stock and batch details
        $centerIds = $records->pluck('center_id')->filter()->unique()->values()->all();

        // collect product ids per record
        $productIds = [];
        foreach ($records as $rec) {
            foreach ($rec->items as $it) {
                $pid = $it->product_id ?? ($it->product->id ?? null);
                if ($pid) $productIds[] = (int)$pid;
            }
        }
        $productIds = array_values(array_unique($productIds));

        $stockMap = [];
        $batchMap = [];
        if (!empty($centerIds) && !empty($productIds)) {
            // Aggregate total per center/product
            $stockRows = inventory_stock::selectRaw('product_id, center_id, SUM(quantity) as qty')
                ->whereIn('center_id', $centerIds)
                ->whereIn('product_id', $productIds)
                ->groupBy('product_id', 'center_id')
                ->get();

            foreach ($stockRows as $row) {
                $key = $row->center_id . '|' . $row->product_id;
                $stockMap[$key] = (int)$row->qty;
            }

            // Fetch batch-level rows for each product/center
            $batchRows = inventory_stock::selectRaw('product_id, center_id, batch_number, SUM(quantity) as qty')
                ->whereIn('center_id', $centerIds)
                ->whereIn('product_id', $productIds)
                ->groupBy('product_id', 'center_id', 'batch_number')
                ->get();

            foreach ($batchRows as $row) {
                $key = $row->center_id . '|' . $row->product_id;
                if (!isset($batchMap[$key])) $batchMap[$key] = [];
                $batchMap[$key][] = [
                    'batch_number' => $row->batch_number,
                    'quantity' => (int)$row->qty,
                ];
            }
        }

        // Attach `current_stock` and `batches` to each item
        foreach ($records as $rec) {
            $center = $rec->center_id;
            foreach ($rec->items as $it) {
                $pid = $it->product_id ?? ($it->product->id ?? null);
                $key = ($center ? $center : '') . '|' . ($pid ? $pid : '');
                $it->current_stock = isset($stockMap[$key]) ? (int)$stockMap[$key] : 0;
                $it->batches = $batchMap[$key] ?? [];
            }
        }

        return response()->json([
            'data' => $records,
        ], 200);
    }


    // ======================================================================
    // LIST INVOICES - GET ALL INVOICES
    // ======================================================================
    /**
     * GET /api/invoices
     * Return all invoice inventory records with related data
     */
    public function listInvoices(Request $request)
    {
        $allowedStatuses = ['pending', 'reject', 'completed'];

        $query = Inventory::with([
            'creator:id,name',
            'approver:id,name',
            'customer:id,name',
            'items.product',
            'latestPayment',
        ])->where('voucherNumber', 'like', 'INV-%');

        $statusFilter = $request->input('status');
        if ($statusFilter !== null) {
            $normalizedStatus = strtolower(trim($statusFilter));
            if (!in_array($normalizedStatus, $allowedStatuses, true)) {
                return response()->json([
                    'message' => 'Invalid status filter. Allowed: ' . implode(', ', $allowedStatuses) . '.',
                ], 422);
            }
            $query->where('status', $normalizedStatus);
        }

        if ($request->filled('center_id')) {
            $query->where('center_id', (int)$request->input('center_id'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int)$request->input('customer_id'));
        }

        $records = $query->orderByDesc('id')->get();

        // We'll collect all center_ids and product_ids used and query stocks in a single query
        $centerIds = $records->pluck('center_id')->filter()->unique()->values()->all();
        // collect product ids per record
        $productIds = [];
        foreach ($records as $rec) {
            foreach ($rec->items as $it) {
                $pid = $it->product_id ?? ($it->product->id ?? null);
                if ($pid) $productIds[] = (int)$pid;
            }
        }
        $productIds = array_values(array_unique($productIds));

        $stockMap = [];
        $batchMap = [];
        if (!empty($centerIds) && !empty($productIds)) {
            $stockRows = inventory_stock::selectRaw('product_id, center_id, SUM(quantity) as qty')
                ->whereIn('center_id', $centerIds)
                ->whereIn('product_id', $productIds)
                ->groupBy('product_id', 'center_id')
                ->get();

            foreach ($stockRows as $row) {
                $key = $row->center_id . '|' . $row->product_id;
                $stockMap[$key] = (int)$row->qty;
            }

            // Fetch batch-level rows for each product/center
            $batchRows = inventory_stock::selectRaw('product_id, center_id, batch_number, SUM(quantity) as qty')
                ->whereIn('center_id', $centerIds)
                ->whereIn('product_id', $productIds)
                ->groupBy('product_id', 'center_id', 'batch_number')
                ->get();

            foreach ($batchRows as $row) {
                $key = $row->center_id . '|' . $row->product_id;
                if (!isset($batchMap[$key])) $batchMap[$key] = [];
                $batchMap[$key][] = [
                    'batch_number' => $row->batch_number,
                    'quantity' => (int)$row->qty,
                ];
            }
        }

        // Attach `current_stock` and `batches` to each item (0 if not found)
        foreach ($records as $rec) {
            $center = $rec->center_id;
            foreach ($rec->items as $it) {
                $pid = $it->product_id ?? ($it->product->id ?? null);
                $key = ($center ? $center : '') . '|' . ($pid ? $pid : '');
                $it->current_stock = isset($stockMap[$key]) ? (int)$stockMap[$key] : 0;
                $it->batches = $batchMap[$key] ?? [];
            }
        }

        return response()->json([
            'data' => $records,
        ], 200);
    }

    /**
     * GET /api/inventory-pending
     * Return all inventory records with status 'pending' and related data
     */
    public function getPending(Request $request)
    {
        $query = Inventory::with([
            'creator:id,name',
            'approver:id,name',
            'customer:id,name',
            'items.product',
            'latestPayment',
        ])->where('status', 'pending');

        if ($request->filled('center_id')) {
            $query->where('center_id', (int)$request->input('center_id'));
        }

        if ($request->filled('type')) {
            // optional type filter: 'invoice', 'grn', 'sales_order', 'sales_return'
            $type = strtolower($request->input('type'));
            if ($type === 'invoice') {
                $query->where('voucherNumber', 'like', 'INV-%');
            } elseif ($type === 'sales_order') {
                $query->where('voucherNumber', 'like', 'SO-%');
            } elseif ($type === 'sales_return') {
                $query->where('voucherNumber', 'like', 'SRET-%');
            } elseif ($type === 'grn') {
                $query->where('voucherNumber', 'like', 'GRN-%');
            }
        }

        $records = $query->orderByDesc('id')->get();

        return response()->json([
            'data' => $records,
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


    // STORE SALES RETURN - ROUTE NORMALIZER
    /**
     * POST /api/salesreturn
     * Normalize incoming sales-return payload and delegate to store()
     */
    public function storeSalesReturn(Request $request)
    {
        $normalizations = [
            'type' => 'sales_return',
        ];

        // Accept orderNumber as voucherNumber if provided
        if (!$request->has('voucherNumber') && !$request->has('voucher_number')) {
            $orderNumber = $request->input('orderNumber') ?? $request->input('order_number');
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


    // ======================================================================
    // STORE STOCK TRANSFER - HANDLE CENTER TO CENTER MOVEMENTS
    // ======================================================================
    /**
     * POST /api/stock-transfer
     * Persist a stock transfer, related inventory lines, and adjust inventory stock
     */
    public function storeStockTransfer(Request $request)
    {
        $payload = $request->input('payload');
        if (!is_array($payload)) {
            $payload = [];
        }

        $items = $payload['items'] ?? $request->input('items', []);
        if (!is_array($items) || empty($items)) {
            return response()->json([
                'message' => 'At least one transfer line item is required.',
            ], 422);
        }

        $creatorId = optional($request->user())->id
            ?? $request->input('created_by')
            ?? $request->input('createdBy')
            ?? $payload['created_by'] ?? $payload['createdBy'] ?? null;

        if (!$creatorId) {
            return response()->json([
                'message' => 'Unauthenticated: provide a valid token or createdBy user id.'
            ], 401);
        }

        $fromCenterInput = $payload['from_center']
            ?? $payload['fromCenter']
            ?? $request->input('from_center')
            ?? $request->input('fromCenter');

        $toCenterInput = $payload['to_center']
            ?? $payload['toCenter']
            ?? $request->input('to_center')
            ?? $request->input('toCenter');

        $fromCenter = $fromCenterInput !== null ? (int)$fromCenterInput : null;
        $toCenter = $toCenterInput !== null ? (int)$toCenterInput : null;

        if (!$fromCenter || !$toCenter) {
            return response()->json([
                'message' => 'Both fromCenter and toCenter are required for stock transfers.'
            ], 422);
        }

        if ($fromCenter === $toCenter) {
            return response()->json([
                'message' => 'fromCenter and toCenter must be different.'
            ], 422);
        }

        $existingCenterIds = centers::whereIn('id', [$fromCenter, $toCenter])->pluck('id')->all();
        $missingCenters = array_diff([$fromCenter, $toCenter], $existingCenterIds);
        if (!empty($missingCenters)) {
            return response()->json([
                'message' => 'One or more centers referenced do not exist.',
                'missing_center_ids' => array_values($missingCenters),
            ], 422);
        }

        $allowedStatuses = ['pending', 'reject', 'completed'];
        $statusInput = $payload['status'] ?? $request->input('status');
        $status = 'pending';
        if ($statusInput !== null) {
            $normalizedStatus = strtolower(trim($statusInput));
            if (!in_array($normalizedStatus, $allowedStatuses, true)) {
                return response()->json([
                    'message' => 'Invalid status value. Allowed: pending, reject, completed.'
                ], 422);
            }
            $status = $normalizedStatus;
        }

        $linePayloads = [];
        foreach ($items as $line) {
            if (!is_array($line)) {
                continue;
            }

            $productId = $line['product_id']
                ?? $line['productId']
                ?? $line['id']
                ?? data_get($line, 'product.id');

            $quantity = (int)($line['quantity'] ?? $line['qty'] ?? 0);

            if (!$productId || $quantity <= 0) {
                continue;
            }

            $unitPrice = (float)($line['unitPrice'] ?? $line['cost'] ?? 0);
            $minPrice = (float)($line['min_price'] ?? $line['minPrice'] ?? 0);
            $mrp = (float)($line['mrp'] ?? 0);
            $lineAmount = 0;

            $linePayloads[] = [
                'product_id' => (int)$productId,
                'quantity' => $quantity,
                'cost' => $unitPrice,
                'min_price' => $minPrice,
                'mrp' => $mrp,
                'amount' => 0,
                'created_by' => (int)$creatorId,
            ];
        }

        if (empty($linePayloads)) {
            return response()->json([
                'message' => 'No valid transfer lines were provided.',
            ], 422);
        }

        $productIds = array_values(array_unique(array_column($linePayloads, 'product_id')));
        $existingProductIds = product::whereIn('id', $productIds)->pluck('id')->all();
        $missingProductIds = array_diff($productIds, $existingProductIds);
        if (!empty($missingProductIds)) {
            return response()->json([
                'message' => 'One or more products referenced do not exist.',
                'missing_product_ids' => array_values($missingProductIds),
            ], 422);
        }

        $stockLines = $this->extractStockLines($items, $linePayloads);
        $voucherNumberInput = $request->input('transferId')
            ?? $request->input('voucherNumber')
            ?? $payload['voucherNumber']
            ?? $payload['id']
            ?? null;

        $record = DB::transaction(function () use (
            $voucherNumberInput,
            $status,
            $creatorId,
            $fromCenter,
            $toCenter,
            $linePayloads,
            $stockLines
        ) {
            $voucherNumber = $voucherNumberInput;
            if (!$voucherNumber) {
                $voucherNumber = $this->buildNextVoucherResponse('stock_transfer', true)['next'];
            }

            $inventory = Inventory::create([
                'voucherNumber' => $voucherNumber,
                'amount' => 0,
                'paid_value' => 0,
                'discountValue' => 0,
                'referNumber' => null,
                'refervoucherNumber' => null,
                'center_id' => $fromCenter,
                'supplier_id' => null,
                'customer_id' => null,
                'from_center' => $fromCenter,
                'to_center' => $toCenter,
                'is_ref' => false,
                'status' => $status,
                'created_by' => $creatorId,
                'approved_by' => null,
            ]);

            $inventory->items()->createMany($linePayloads);

            if (!empty($stockLines)) {
                // subtract from source, add to destination
                $this->applyInventoryStockAdjustments($stockLines, $fromCenter, (int)$creatorId, 'subtract');
                $this->applyInventoryStockAdjustments($stockLines, $toCenter, (int)$creatorId, 'add');
            }

            return $inventory;
        });

        $record->load(['creator:id,name', 'items.product']);

        return response()->json([
            'message' => 'Stock transfer saved successfully',
            'data' => $record,
            'document_type' => 'stock_transfer',
        ], 201);
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
            'sales_return' => "SRET-{$year}-",
            'stock_transfer' => "ST-{$year}-",
            'purchase_order' => "PO-{$year}-",
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

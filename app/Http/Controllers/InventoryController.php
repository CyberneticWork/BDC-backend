<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Payment;
use App\Models\product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
      // GET /api/inventories -> return all inventory records
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

      // POST /api/grn -> create a GRN as an inventory record
      public function store(Request $request)
      {
            // Support flexible payload keys from the frontend
            $referNumber = $request->input('referNumber')
                  ?? $request->input('refNumber');

            $discountValue = $request->input('discountValue');
            if ($discountValue === null) {
                  $discountValue = $request->input('discount', 0);
            }

            $amount = $request->input('amount');
            if ($amount === null) {
                  $amount = $request->input('total', $request->input('totalAmount', 0));
            }

            $paid_value = $request->input('paid_value');
            if ($paid_value === null) {
                  $paid_value = $request->input('paid', 0);
            }

            $quantity = $request->input('quantity');
            if ($quantity === null) {
                  $quantity = $request->input('qty');
            }

            $unitPrice = $request->input('unitPrice');

            // If item level payloads are provided under any supported key, derive sensible defaults
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

            if (!empty($items)) {
                  if ($quantity === null) {
                        $quantity = collect($items)->sum(function ($it) {
                              return (int)($it['quantity'] ?? $it['qty'] ?? data_get($it, 'pivot.quantity', 0));
                        });
                  }

                  if ($unitPrice === null) {
                        $firstItem = $items[0];
                        $unitPrice = (float)($firstItem['unitPrice'] ?? $firstItem['cost'] ?? data_get($firstItem, 'pivot.cost', 0));
                  }

                  if ($amount === null || (float)$amount <= 0) {
                        $amount = collect($items)->sum(function ($it) {
                              $q = (float)($it['quantity'] ?? $it['qty'] ?? data_get($it, 'pivot.quantity', 0));
                              $u = (float)($it['unitPrice'] ?? $it['cost'] ?? data_get($it, 'pivot.cost', 0));
                              $lineAmount = $it['amount'] ?? $it['total'] ?? data_get($it, 'pivot.amount');

                              return $lineAmount !== null ? (float)$lineAmount : $q * $u;
                        });
                  }
            }

            // Final fallbacks
            $referNumber = $referNumber ? (string)$referNumber : null;
            $discountValue = (float)$discountValue;
            $quantity = (int)($quantity ?? 0);
            $unitPrice = (float)($unitPrice ?? 0);
            $amount = (float)($amount ?? 0);
            $paid_value = (float)($paid_value ?? 0);

            // Resolve creator (require authenticated user or explicit created_by)
            $creatorId = optional($request->user())->id ?? $request->input('created_by');
            if (!$creatorId) {
                  return response()->json([
                        'message' => 'Unauthenticated: provide a valid token or created_by user id.'
                  ], 401);
            }

            // Prepare inventory product payloads ahead of persistence
            $linePayloads = [];
            if (!empty($items)) {
                  foreach ($items as $line) {
                        if (!is_array($line)) {
                              continue;
                        }

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
                        if ($lineAmount === null) {
                              $lineAmount = $lineQty * (float)$lineCost;
                        }

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

            if ($quantity <= 0 && !empty($linePayloads)) {
                  $quantity = array_sum(array_column($linePayloads, 'quantity'));
            }

            if ($amount <= 0 && !empty($linePayloads)) {
                  $amount = array_sum(array_column($linePayloads, 'amount'));
            }

            if ($unitPrice <= 0 && !empty($linePayloads)) {
                  $unitPrice = (float)($linePayloads[0]['cost'] ?? 0);
            }

            if (empty($linePayloads)) {
                  return response()->json([
                        'message' => 'No valid inventory item lines were provided.',
                  ], 422);
            }

            $productIds = array_unique(array_column($linePayloads, 'product_id'));
            if (!empty($productIds)) {
                  $existingIds = product::whereIn('id', $productIds)->pluck('id')->all();
                  $missingIds = array_diff($productIds, $existingIds);
                  if (!empty($missingIds)) {
                        return response()->json([
                              'message' => 'One or more products referenced in the GRN do not exist.',
                              'missing_product_ids' => array_values($missingIds),
                        ], 422);
                  }
            }

            // Foreign keys (accept both snake_case and camelCase)
            $center_id   = $request->input('center_id', $request->input('centerId'));
            $supplier_id = $request->input('supplier_id', $request->input('supplierId'));
            $customer_id = $request->input('customer_id', $request->input('customerId'));
            $from_center = $request->input('from_center', $request->input('fromCenter'));
            $to_center   = $request->input('to_center', $request->input('toCenter'));

            // Always store status as pending regardless of input
            $status = 'pending';

            // Save payment details (if provided or paid_value > 0)
            $paymentInput = $request->input('payment', []);
            $paymentAmount = isset($paymentInput['amount']) ? (float)$paymentInput['amount'] : $paid_value;

            // Also support payment fields at root level for backwards compatibility
            $paymentMode = $paymentInput['mode'] ?? $request->input('mode') ?? null;
            $paymentNote = $paymentInput['note'] ?? $request->input('note') ?? null;
            $bankName = $paymentInput['bankName'] ?? $paymentInput['bank_name'] ?? $request->input('bankName') ?? $request->input('bank_name');
            $chequeNo = $paymentInput['chequeNo'] ?? $paymentInput['cheque_no'] ?? $request->input('chequeNo') ?? $request->input('cheque_no');
            $chequeDate = $paymentInput['chequeDate'] ?? $paymentInput['cheque_date'] ?? $request->input('chequeDate') ?? $request->input('cheque_date');

            // Persist inventory, items, and payment atomically
            $result = DB::transaction(function () use (
                  $discountValue,
                  $quantity,
                  $unitPrice,
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
                  $paymentAmount,
                  $paymentMode,
                  $paymentNote,
                  $bankName,
                  $chequeNo,
                  $chequeDate
            ) {
                  $year = date('y');
                  $prefix = "GRN-{$year}-";
                  $latest = Inventory::where('voucherNumber', 'like', $prefix . '%')
                        ->lockForUpdate()
                        ->orderBy('voucherNumber', 'desc')
                        ->value('voucherNumber');
                  $maxNum = $latest ? (int)substr($latest, strlen($prefix)) : 0;
                  $voucherNumber = $prefix . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);

                  $record = Inventory::create([
                        'voucherNumber' => $voucherNumber,
                        'unitPrice' => $unitPrice,
                        'quantity' => $quantity,
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
                  ]);

                  $createdItems = [];
                  if (!empty($linePayloads)) {
                        $createdItems = $record->items()->createMany($linePayloads);
                  }

                  $payment = null;
                  if ($paymentAmount && (float)$paymentAmount > 0) {
                        $payment = Payment::create([
                              'inventory_id' => $record->id,
                              'amount' => (float)$paymentAmount,
                              'mode' => $paymentMode,
                              'note' => $paymentNote,
                              'bank_name' => $bankName,
                              'cheque_no' => $chequeNo,
                              'cheque_date' => $chequeDate,
                              'created_by' => $creatorId,
                        ]);
                  }

                  return [
                        'record' => $record,
                        'items' => $createdItems,
                        'payment' => $payment,
                  ];
            });

            $record = $result['record'];
            $payment = $result['payment'];

            return response()->json([
                  'message' => 'GRN saved successfully',
                  'data' => tap($record->load(['creator:id,name', 'approver:id,name', 'items.product']), function ($loaded) use ($payment) {
                        if ($payment) {
                              $loaded->payment = $payment;
                        }
                  }),
            ], 201);
      }

      // GET /api/inventories/{id} -> return a single inventory record
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

      // PUT/PATCH /api/inventories/{id} -> update an inventory record
      public function update(Request $request, $id)
      {
            $record = Inventory::find($id);
            if (!$record) {
                  return response()->json([
                        'message' => 'Inventory record not found.'
                  ], 404);
            }

            // Flexible inputs similar to store()
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

            $quantity = $request->input('quantity');
            if ($quantity === null) {
                  $quantity = $request->input('qty');
            }

            $unitPrice = $request->input('unitPrice');

            // If items[] are provided, derive sensible defaults when missing
            $items = $request->input('items', []);
            if (is_array($items) && count($items) > 0) {
                  if ($quantity === null) {
                        $quantity = collect($items)->sum(function ($it) {
                              return (int)($it['quantity'] ?? 0);
                        });
                  }
                  if ($unitPrice === null) {
                        $firstItem = $items[0];
                        $unitPrice = (float)($firstItem['unitPrice'] ?? 0);
                  }
                  if ($amount === null || (float)$amount <= 0) {
                        $amount = collect($items)->sum(function ($it) {
                              $q = (float)($it['quantity'] ?? 0);
                              $u = (float)($it['unitPrice'] ?? 0);
                              return $q * $u;
                        });
                  }
            }

            // Optional status, restrict to allowed enum values
            $status = $request->input('status');
            $allowedStatus = ['pending', 'reject', 'completed'];
            if ($status !== null && !in_array($status, $allowedStatus, true)) {
                  return response()->json([
                        'message' => 'Invalid status value. Allowed: pending, reject, completed.'
                  ], 422);
            }

            // Foreign keys
            $center_id   = $request->input('center_id');
            $supplier_id = $request->input('supplier_id');
            $customer_id = $request->input('customer_id');
            $from_center = $request->input('from_center');
            $to_center   = $request->input('to_center');

            // Build payload using only provided values
            $payload = [];
            if ($voucherNumber !== null) $payload['voucherNumber'] = (string)$voucherNumber;
            if ($unitPrice !== null)     $payload['unitPrice'] = (float)$unitPrice;
            if ($quantity !== null)      $payload['quantity'] = (int)$quantity;
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

            if (empty($payload)) {
                  return response()->json([
                        'message' => 'No updatable fields provided.'
                  ], 422);
            }

            $record->update($payload);

            $record->load(['creator:id,name', 'approver:id,name']);

            return response()->json([
                  'message' => 'Inventory updated successfully',
                  'data' => $record,
            ], 200);
      }

      // DELETE /api/inventories/{id} -> soft delete an inventory
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

      // GET /api/grn/next -> preview next GRN number without creating a record
      public function nextGrn()
      {
            $year = date('y');
            $prefix = "GRN-{$year}-";
            $latest = Inventory::where('voucherNumber', 'like', $prefix . '%')
                  ->orderBy('voucherNumber', 'desc')
                  ->value('voucherNumber');
            $maxNum = 0;
            if ($latest) {
                  $maxNum = (int)substr($latest, strlen($prefix));
            }
            $nextNum = $maxNum + 1;
            $voucher = $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
            return response()->json([
                  'data' => [
                        'next' => $voucher,
                        'year' => $year,
                        'sequence' => $nextNum,
                  ]
            ], 200);
      }

      // POST /api/invoices -> create an Invoice as an inventory record
      public function storeInvoice(Request $request)
      {
            // Extract data from the request
            $voucherNumber = $request->input('id'); // Frontend sends id as INV-0001
            $centerId = $request->input('center');
            $customerName = $request->input('customer');
            $date = $request->input('date');
            $refNumber = $request->input('refNumber');
            $amount = (float)($request->input('amount') ?? 0);
            $items = $request->input('items', []);
            $paymentData = $request->input('payment', []);
            $createdBy = $request->input('created_by');

            // Validation
            if (!$createdBy) {
                  return response()->json([
                        'message' => 'Created by user is required.'
                  ], 401);
            }

            if (empty($items)) {
                  return response()->json([
                        'message' => 'No items provided for the invoice.'
                  ], 422);
            }

            // Find or validate customer
            $customer = \App\Models\Customer::where('name', $customerName)->first();
            if (!$customer) {
                  return response()->json([
                        'message' => "Customer '{$customerName}' not found."
                  ], 422);
            }

            // Prepare inventory product payloads
            $linePayloads = [];
            $totalQuantity = 0;
            $totalAmount = 0;

            foreach ($items as $line) {
                  if (!is_array($line)) {
                        continue;
                  }

                  $productId = $line['productId'] ?? null;
                  $lineQty = (int)($line['quantity'] ?? 0);
                  $lineCost = (float)($line['unitPrice'] ?? 0);
                  $lineDiscount = (float)($line['discount'] ?? 0);

                  // Calculate line amount: (unitPrice * quantity) - (discount * quantity)
                  $lineAmount = ($lineCost * $lineQty) - ($lineDiscount * $lineQty);

                  if ($productId && $lineQty > 0) {
                        // Verify product exists
                        $product = product::find($productId);
                        if (!$product) {
                              return response()->json([
                                    'message' => "Product ID {$productId} not found."
                              ], 422);
                        }

                        $linePayloads[] = [
                              'product_id' => (int)$productId,
                              'quantity' => $lineQty,
                              'cost' => $lineCost,
                              'min_price' => (float)($product->min_price ?? 0),
                              'mrp' => (float)($product->mrp ?? 0),
                              'amount' => $lineAmount,
                              'created_by' => $createdBy,
                        ];

                        $totalQuantity += $lineQty;
                        $totalAmount += $lineAmount;
                  }
            }

            if (empty($linePayloads)) {
                  return response()->json([
                        'message' => 'No valid invoice items were provided.',
                  ], 422);
            }

            // Use the calculated total if amount not provided or zero
            if ($amount <= 0) {
                  $amount = $totalAmount;
            }

            // Extract payment details
            $paymentAmount = (float)($paymentData['amount'] ?? 0);
            $paymentMode = $paymentData['mode'] ?? null;
            $paymentNote = $paymentData['note'] ?? null;
            $bankName = $paymentData['bankName'] ?? $paymentData['bank_name'] ?? null;
            $chequeNo = $paymentData['chequeNo'] ?? $paymentData['cheque_no'] ?? null;
            $chequeDate = $paymentData['chequeDate'] ?? $paymentData['cheque_date'] ?? null;
            $referenceNo = $paymentData['referenceNo'] ?? $paymentData['reference_no'] ?? null;
            $transferDate = $paymentData['transferDate'] ?? $paymentData['transfer_date'] ?? null;

            // Persist inventory, items, and payment atomically
            $result = DB::transaction(function () use (
                  $voucherNumber,
                  $totalQuantity,
                  $amount,
                  $refNumber,
                  $centerId,
                  $customer,
                  $createdBy,
                  $linePayloads,
                  $paymentAmount,
                  $paymentMode,
                  $paymentNote,
                  $bankName,
                  $chequeNo,
                  $chequeDate,
                  $referenceNo,
                  $transferDate
            ) {
                  // If voucherNumber not provided, generate one
                  if (!$voucherNumber) {
                        $year = date('y');
                        $prefix = "INV-{$year}-";
                        $latest = Inventory::where('voucherNumber', 'like', $prefix . '%')
                              ->lockForUpdate()
                              ->orderBy('voucherNumber', 'desc')
                              ->value('voucherNumber');
                        $maxNum = $latest ? (int)substr($latest, strlen($prefix)) : 0;
                        $voucherNumber = $prefix . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);
                  }

                  // Get the first item's cost for unitPrice
                  $unitPrice = $linePayloads[0]['cost'] ?? 0;

                  $record = Inventory::create([
                        'voucherNumber' => $voucherNumber,
                        'unitPrice' => $unitPrice,
                        'quantity' => $totalQuantity,
                        'amount' => $amount,
                        'paid_value' => $paymentAmount,
                        'discountValue' => 0, // Can be calculated from items if needed
                        'referNumber' => $refNumber,
                        'center_id' => $centerId,
                        'supplier_id' => null,
                        'customer_id' => $customer->id,
                        'from_center' => null,
                        'to_center' => null,
                        'status' => 'pending',
                        'created_by' => $createdBy,
                        'approved_by' => null,
                  ]);

                  $createdItems = [];
                  if (!empty($linePayloads)) {
                        $createdItems = $record->items()->createMany($linePayloads);
                  }

                  $payment = null;
                  if ($paymentAmount && (float)$paymentAmount > 0) {
                        // Build note combining all payment details
                        $noteDetails = [];
                        if ($paymentNote) $noteDetails[] = $paymentNote;
                        if ($referenceNo) $noteDetails[] = "Ref: {$referenceNo}";
                        if ($transferDate) $noteDetails[] = "Date: {$transferDate}";

                        $finalNote = !empty($noteDetails) ? implode(' | ', $noteDetails) : $paymentNote;

                        $payment = Payment::create([
                              'inventory_id' => $record->id,
                              'amount' => (float)$paymentAmount,
                              'mode' => $paymentMode,
                              'note' => $finalNote,
                              'bank_name' => $bankName,
                              'cheque_no' => $chequeNo,
                              'cheque_date' => $chequeDate,
                              'created_by' => $createdBy,
                        ]);
                  }

                  return [
                        'record' => $record,
                        'items' => $createdItems,
                        'payment' => $payment,
                  ];
            });

            $record = $result['record'];
            $payment = $result['payment'];

            return response()->json([
                  'message' => 'Invoice saved successfully',
                  'data' => tap($record->load(['creator:id,name', 'approver:id,name', 'items.product', 'customer']), function ($loaded) use ($payment) {
                        if ($payment) {
                              $loaded->payment = $payment;
                        }
                  }),
            ], 201);
      }
}

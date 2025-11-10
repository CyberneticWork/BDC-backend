<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Payment;
use App\Models\inventory_product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
            $voucherNumber = $request->input('voucherNumber')
                  ?? $request->input('grnNumber')
                  ?? $request->input('id');

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

            // If items[] are provided, derive sensible defaults
            $items = $request->input('items', []);
            if (is_array($items) && count($items) > 0) {
                  // Sum quantities
                  if ($quantity === null) {
                        $quantity = collect($items)->sum(function ($it) {
                              return (int)($it['quantity'] ?? 0);
                        });
                  }
                  // Use first line's unitPrice if not provided
                  if ($unitPrice === null) {
                        $firstItem = $items[0];
                        $unitPrice = (float)($firstItem['unitPrice'] ?? 0);
                  }
                  // Calculate total amount if not provided
                  if ($amount === null || (float)$amount <= 0) {
                        $amount = collect($items)->sum(function ($it) {
                              $q = (float)($it['quantity'] ?? 0);
                              $u = (float)($it['unitPrice'] ?? 0);
                              return $q * $u;
                        });
                  }
            }

            // Final fallbacks
            $voucherNumber = (string)$voucherNumber;
            $referNumber = $referNumber ? (string)$referNumber : null;
            $discountValue = (float)$discountValue;
            $quantity = (int)($quantity ?? 0);
            $unitPrice = (float)($unitPrice ?? 0);
            $amount = (float)($amount ?? 0);
            $paid_value = (float)($paid_value ?? 0);

            // Always store status as pending regardless of input
            $status = 'pending';

            // Resolve creator (require authenticated user or explicit created_by)
            $creatorId = optional($request->user())->id ?? $request->input('created_by');
            if (!$creatorId) {
                  return response()->json([
                        'message' => 'Unauthenticated: provide a valid token or created_by user id.'
                  ], 401);
            }

            if (!$voucherNumber) {
                  return response()->json([
                        'message' => 'The GRN number (voucherNumber/grnNumber/id) is required.'
                  ], 422);
            }

            // Persist
            $record = Inventory::create([
                  'voucherNumber' => $voucherNumber,
                  'unitPrice' => $unitPrice,
                  'quantity' => $quantity,
                  'amount' => $amount,
                  'paid_value' => $paid_value,
                  'discountValue' => $discountValue,
                  'referNumber' => $referNumber,
                  'status' => $status,
                  'created_by' => $creatorId,
                  'approved_by' => null,
            ]);

            // Persist line items into inventory_products table
            $createdItems = [];
            if (is_array($items) && count($items) > 0) {
                  foreach ($items as $line) {
                        $productId = $line['product_id'] ?? $line['productId'] ?? $line['id'] ?? null;
                        $lineQty   = isset($line['quantity']) ? (int)$line['quantity'] : (isset($line['qty']) ? (int)$line['qty'] : 0);
                        // unitPrice from payload should be stored as cost
                        $lineCost  = isset($line['unitPrice']) ? (float)$line['unitPrice'] : (isset($line['cost']) ? (float)$line['cost'] : 0.0);
                        $minPrice  = isset($line['min_price']) ? (float)$line['min_price'] : (isset($line['minPrice']) ? (float)$line['minPrice'] : 0.0);
                        $mrp       = isset($line['mrp']) ? (float)$line['mrp'] : 0.0;
                        $lineAmt   = isset($line['amount']) ? (float)$line['amount'] : (float)($lineQty * $lineCost);

                        if ($productId && $lineQty > 0) {
                              $createdItems[] = inventory_product::create([
                                    'inventory_id' => $record->id,
                                    'product_id'   => (int)$productId,
                                    'quantity'     => $lineQty,
                                    'cost'         => $lineCost,
                                    'min_price'    => $minPrice,
                                    'mrp'          => $mrp,
                                    'amount'       => $lineAmt,
                                    'created_by'   => $creatorId,
                              ]);
                        }
                  }
            } else {
                  // Back-compat: allow single product on root level
                  $rootProductId = $request->input('product_id') ?? $request->input('productId') ?? $request->input('product');
                  if ($rootProductId && $quantity > 0) {
                        $createdItems[] = inventory_product::create([
                              'inventory_id' => $record->id,
                              'product_id'   => (int)$rootProductId,
                              'quantity'     => (int)$quantity,
                              'cost'         => (float)$unitPrice,
                              'min_price'    => (float)($request->input('min_price') ?? $request->input('minPrice') ?? 0),
                              'mrp'          => (float)($request->input('mrp') ?? 0),
                              'amount'       => (float)($request->input('lineAmount') ?? ($quantity * $unitPrice)),
                              'created_by'   => $creatorId,
                        ]);
                  }
            }

            // Save payment details (if provided or paid_value > 0)
            $paymentInput = $request->input('payment', []);
            $paymentAmount = isset($paymentInput['amount']) ? (float)$paymentInput['amount'] : $paid_value;

            // Also support payment fields at root level for backwards compatibility
            $paymentMode = $paymentInput['mode'] ?? $request->input('mode') ?? null;
            $paymentNote = $paymentInput['note'] ?? $request->input('note') ?? null;
            $bankName = $paymentInput['bankName'] ?? $paymentInput['bank_name'] ?? $request->input('bankName') ?? $request->input('bank_name');
            $chequeNo = $paymentInput['chequeNo'] ?? $paymentInput['cheque_no'] ?? $request->input('chequeNo') ?? $request->input('cheque_no');
            $chequeDate = $paymentInput['chequeDate'] ?? $paymentInput['cheque_date'] ?? $request->input('chequeDate') ?? $request->input('cheque_date');

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

                  // attach payment to response (optional)
                  $record->payment = $payment;
            }

            return response()->json([
                  'message' => 'GRN saved successfully',
                  'data' => $record->load(['creator:id,name','approver:id,name','items']),
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
            if ($center_id !== null)     $payload['center_id'] = $center_id ?: null;
            if ($supplier_id !== null)   $payload['supplier_id'] = $supplier_id ?: null;
            if ($customer_id !== null)   $payload['customer_id'] = $customer_id ?: null;
            if ($from_center !== null)   $payload['from_center'] = $from_center ?: null;
            if ($to_center !== null)     $payload['to_center'] = $to_center ?: null;

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

}

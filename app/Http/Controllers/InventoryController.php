<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
      // GET /api/inventories -> return all inventory records
      public function index()
      {
            $inventory = Inventory::with([
                  'creator:id,name',
                  'approver:id,name',
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
            $referNumber = $referNumber ? (string)$referNumber : null;
            $discountValue = (float)$discountValue;
            $quantity = (int)($quantity ?? 0);
            $unitPrice = (float)($unitPrice ?? 0);
            $amount = (float)($amount ?? 0);
            $paid_value = (float)($paid_value ?? 0);

            // Foreign keys (accept both snake_case and camelCase)
            $center_id   = $request->input('center_id', $request->input('centerId'));
            $supplier_id = $request->input('supplier_id', $request->input('supplierId'));
            $customer_id = $request->input('customer_id', $request->input('customerId'));
            $from_center = $request->input('from_center', $request->input('fromCenter'));
            $to_center   = $request->input('to_center', $request->input('toCenter'));

            // Always store status as pending regardless of input
            $status = 'pending';

            // Resolve creator (require authenticated user or explicit created_by)
            $creatorId = optional($request->user())->id ?? $request->input('created_by');
            if (!$creatorId) {
                  return response()->json([
                        'message' => 'Unauthenticated: provide a valid token or created_by user id.'
                  ], 401);
            }

            // Auto-generate GRN number: GRN-YY-XXXX (transactional to minimize race conditions)
            $voucherNumber = DB::transaction(function () {
                  $year = date('y');
                  $prefix = "GRN-{$year}-";
                  // Get latest voucher and increment its sequence
                  $latest = Inventory::where('voucherNumber', 'like', $prefix . '%')
                        ->lockForUpdate()
                        ->orderBy('voucherNumber', 'desc')
                        ->value('voucherNumber');
                  $maxNum = 0;
                  if ($latest) {
                        $maxNum = (int)substr($latest, strlen($prefix));
                  }
                  $nextNum = $maxNum + 1;
                  return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
            });

            

            // Persist
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
                  'data' => $record,
            ], 201);
      }

      // GET /api/inventories/{id} -> return a single inventory record
      public function show($id)
      {
            $record = Inventory::with([
                  'creator:id,name',
                  'approver:id,name',
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
}

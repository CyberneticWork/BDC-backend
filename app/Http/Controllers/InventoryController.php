<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
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

            return response()->json([
                  'message' => 'GRN saved successfully',
                  'data' => $record,
            ], 201);
      }

}

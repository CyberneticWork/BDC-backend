<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    // Show all customers
    public function index()
    {
        $customers = Customer::with(['customerType', 'customerCategory'])->get();
        return response()->json($customers);
    }

    // Store a new customer
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:customers',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'required|string|max:255',
            'customer_type_id' => 'nullable|exists:customer_types,id',
            'customer_category_id' => 'nullable|exists:customer_categories,id',
        ]);

        $customer = Customer::create($validated);
        return response()->json($customer, 201);
    }

    // Show a single customer
    public function show($id)
    {
        $customer = Customer::findOrFail($id);
        return response()->json($customer);
    }

    // Update a customer
    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:customers,email,' . $id,
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'sometimes|required|string|max:255',
            'customer_type_id' => 'nullable|exists:customer_types,id',
            'customer_category_id' => 'nullable|exists:customer_categories,id',
        ]);

        $customer->update($validated);
        return response()->json($customer);
    }

    // Delete a customer
    public function destroy($id)
    {
        $customer = Customer::findOrFail($id);
        $customer->delete();

        return response()->json(['message' => 'Customer deleted successfully']);
    }
}

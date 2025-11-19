<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\JournalEntry;

class JournalEntryController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $query = JournalEntry::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('entry_number', 'like', "%{$search}%")
                    ->orWhere('memo', 'like', "%{$search}%")
                    ->orWhere('account_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $query
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        $entry = DB::transaction(function () use ($data) {
            $entryNumber = $this->generateEntryNumber();

            return JournalEntry::create(array_merge($data, [
                'entry_number' => $entryNumber,
            ]));
        });

        return response()->json($entry, 201);
    }

    public function show(JournalEntry $journalEntry)
    {
        return $journalEntry;
    }

    public function update(Request $request, JournalEntry $journalEntry)
    {
        $data = $this->validatePayload($request, true);

        $journalEntry->fill($data);
        $journalEntry->save();

        return $journalEntry;
    }

    public function destroy(JournalEntry $journalEntry)
    {
        $journalEntry->delete();

        return response()->noContent();
    }

    public function getNextEntryNumber()
    {
        $nextNumber = $this->generateEntryNumber();
        
        return response()->json([
            'entry_number' => $nextNumber
        ]);
    }

    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'entry_date' => [$isUpdate ? 'sometimes' : 'required', 'date'],
            'memo' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'account_type' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'account_name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'debit' => ['nullable', 'numeric', 'min:0'],
            'credit' => ['nullable', 'numeric', 'min:0'],
        ];

        $data = $request->validate($rules);

        $debit = isset($data['debit']) ? (float) $data['debit'] : null;
        $credit = isset($data['credit']) ? (float) $data['credit'] : null;

        if ((!$isUpdate || array_key_exists('debit', $data) || array_key_exists('credit', $data))
            && (($debit ?? 0) <= 0 && ($credit ?? 0) <= 0)
        ) {
            throw ValidationException::withMessages([
                'debit' => 'Either debit or credit must be greater than zero.',
            ]);
        }

        if ($debit && $credit) {
            throw ValidationException::withMessages([
                'credit' => 'Only one of debit or credit can be greater than zero for a line item.',
            ]);
        }

        if (array_key_exists('debit', $data)) {
            $data['debit'] = $debit ? round($debit, 2) : 0.0;
        } elseif (!$isUpdate) {
            $data['debit'] = 0.0;
        }

        if (array_key_exists('credit', $data)) {
            $data['credit'] = $credit ? round($credit, 2) : 0.0;
        } elseif (!$isUpdate) {
            $data['credit'] = 0.0;
        }

        if (array_key_exists('account_type', $data)) {
            $data['account_type'] = strtoupper(trim($data['account_type']));
        }

        return $data;
    }

    private function generateEntryNumber(): string
    {
        $latest = JournalEntry::withTrashed()
            ->lockForUpdate()
            ->orderByDesc('entry_number')
            ->value('entry_number');

        $next = 1;

        if ($latest && preg_match('/JE-(\d+)/', $latest, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        return sprintf('JE-%06d', $next);
    }
}

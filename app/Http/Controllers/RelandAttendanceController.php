<?php

namespace App\Http\Controllers;

use App\Models\company;
use App\Services\CompanyProcessSettings;
use App\Services\RelandExcelImportService;
use Illuminate\Http\Request;

class RelandAttendanceController extends Controller
{
    public function importExcel(Request $request, RelandExcelImportService $importService)
    {
        $validated = $request->validate([
            'file' => 'required|file',
            'company_id' => 'required|exists:companies,id',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        if (!in_array($ext, ['xls', 'xlsx', 'csv'], true)) {
            return response()->json(['message' => 'Upload a Reland .xls or .xlsx file.'], 422);
        }

        $company = company::findOrFail((int) $validated['company_id']);
        if (!CompanyProcessSettings::usesRelandExcelImport($company)) {
            return response()->json([
                'message' => 'Reland Excel import is not enabled for this company. Enable it in Cybernetic Admin.',
            ], 403);
        }

        $result = $importService->import(
            $request->file('file')->getRealPath(),
            (int) $validated['company_id'],
            $validated['from_date'],
            $validated['to_date'] ?? null
        );

        return response()->json([
            'message' => 'Reland import completed',
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
            'data' => $result,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CompanyLocation;
use App\Services\CompanyLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyLocationController extends Controller
{
    public function __construct(private CompanyLocationService $locations)
    {
    }

    public function index(Request $request)
    {
        if (!$this->locations->ready()) {
            return response()->json([]);
        }

        $query = CompanyLocation::query()
            ->leftJoin('companies as c', 'c.id', '=', 'company_locations.company_id')
            ->select('company_locations.*', 'c.name as company_name')
            ->selectSub(
                DB::table('organization_assignments as oa')
                    ->join('employees as e', 'e.organization_assignment_id', '=', 'oa.id')
                    ->whereColumn('oa.location_id', 'company_locations.id')
                    ->where('e.is_active', 1)
                    ->selectRaw('COUNT(*)'),
                'employee_count'
            )
            ->orderBy('c.name')
            ->orderBy('company_locations.name');

        if ($request->filled('company_id')) {
            $query->where('company_locations.company_id', (int) $request->query('company_id'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'name' => 'required|string|max:191',
            'address' => 'nullable|string|max:255',
        ]);

        $this->locations->ensureSchema();

        if ($this->nameTaken($validated['company_id'], $validated['name'])) {
            return response()->json(['message' => 'Location already exists for this company.'], 409);
        }

        return response()->json(CompanyLocation::create($validated), 201);
    }

    public function update(Request $request, $id)
    {
        $this->locations->ensureSchema();
        $location = CompanyLocation::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'address' => 'nullable|string|max:255',
        ]);

        if ($this->nameTaken($location->company_id, $validated['name'], $location->id)) {
            return response()->json(['message' => 'Location already exists for this company.'], 409);
        }

        $location->update($validated);
        return response()->json($location);
    }

    public function destroy($id)
    {
        $this->locations->ensureSchema();
        $location = CompanyLocation::findOrFail($id);

        $activeEmployees = DB::table('organization_assignments as oa')
            ->join('employees as e', 'e.organization_assignment_id', '=', 'oa.id')
            ->where('oa.location_id', $location->id)
            ->where('e.is_active', 1)
            ->count();
        if ($activeEmployees > 0) {
            return response()->json([
                'message' => "{$activeEmployees} active employee(s) are assigned to this location. Move them to another location first.",
            ], 409);
        }

        DB::table('organization_assignments')->where('location_id', $location->id)->update(['location_id' => null]);
        $location->delete();

        return response()->json(['message' => 'Deleted successfully.']);
    }

    private function nameTaken($companyId, string $name, ?int $exceptId = null): bool
    {
        return CompanyLocation::where('company_id', $companyId)
            ->where('name', trim($name))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }
}

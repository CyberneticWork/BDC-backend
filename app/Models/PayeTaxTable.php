<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayeTaxTable extends Model
{
    protected $table = 'paye_tax_tables';

    protected $fillable = [
        'name',
        'code',
        'effective_from',
        'effective_to',
        'personal_relief_monthly',
        'personal_relief_annual',
        'slabs',
        'is_active',
        'description',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'personal_relief_monthly' => 'decimal:2',
        'personal_relief_annual' => 'decimal:2',
        'slabs' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Calculate PAYE for a given monthly taxable earnings and optional date.
     * Uses official Sri Lanka Inland Revenue Department Table 1 formula:
     * Tax = (Monthly Taxable Regular Profits × Tax Rate) − Fixed Deduction
     */
    public static function calculatePaye(float $taxableEarnings, ?string $date = null, ?self $taxTable = null): array
    {
        $taxableEarnings = max(0, round($taxableEarnings, 2));

        if (!$taxTable) {
            $date = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
            $taxTable = self::query()
                ->where('is_active', true)
                ->where('effective_from', '<=', $date)
                ->where(function ($q) use ($date) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
                })
                ->orderBy('effective_from', 'desc')
                ->first();

            if (!$taxTable) {
                // Fallback to latest active table
                $taxTable = self::query()->where('is_active', true)->orderBy('id', 'desc')->first();
            }
        }

        if (!$taxTable) {
            return [
                'tax_table_name' => 'None',
                'tax_table_code' => null,
                'personal_relief_monthly' => 0.0,
                'taxable_amount' => 0.0,
                'taxable_remuneration' => 0.0,
                'paye_amount' => 0.0,
                'applicable_band' => 0,
                'applicable_range' => '',
                'applicable_formula' => 'None',
                'applicable_rate' => 0.0,
                'fixed_deduction' => 0.0,
                'band_breakdown' => [],
                'status' => 'No active tax table found',
            ];
        }

        $relief = (float) $taxTable->personal_relief_monthly;

        // Band 0: Personal Relief / Tax-Free Allowance (Up to Rs. 150,000)
        if ($taxableEarnings <= $relief) {
            return [
                'tax_table_id' => $taxTable->id,
                'tax_table_name' => $taxTable->name,
                'tax_table_code' => $taxTable->code,
                'personal_relief_monthly' => $relief,
                'taxable_amount' => 0.0,
                'taxable_remuneration' => $taxableEarnings,
                'paye_amount' => 0.0,
                'applicable_band' => 0,
                'applicable_range' => 'Up to Rs. ' . number_format($relief),
                'applicable_formula' => 'Tax relief – Nil',
                'applicable_rate' => 0.0,
                'fixed_deduction' => 0.0,
                'band_breakdown' => [],
                'status' => 'Tax relief – Nil',
            ];
        }

        $slabs = is_array($taxTable->slabs) ? $taxTable->slabs : [];
        $applicableSlab = null;
        $currentLower = $relief;
        $cumulativePrior = 0.0;

        foreach ($slabs as $idx => $slab) {
            $bandNum = $slab['band'] ?? ($idx + 1);
            $rate = (float) ($slab['rate'] ?? 0);

            if (isset($slab['min_monthly'])) {
                $min = (float) $slab['min_monthly'];
            } else {
                $min = $currentLower;
            }

            if (isset($slab['max_monthly'])) {
                $max = $slab['max_monthly'] !== null ? (float) $slab['max_monthly'] : null;
            } elseif (isset($slab['threshold']) && is_numeric($slab['threshold']) && (float) $slab['threshold'] > 0) {
                $max = round($min + (float) $slab['threshold'], 2);
            } else {
                $max = null;
            }

            if (isset($slab['fixed_deduction'])) {
                $deduction = (float) $slab['fixed_deduction'];
            } else {
                $deduction = round(($min * ($rate / 100)) - $cumulativePrior, 2);
            }

            $matches = false;
            if ($max !== null) {
                if ($taxableEarnings > $min && $taxableEarnings <= $max) {
                    $matches = true;
                }
            } else {
                if ($taxableEarnings > $min) {
                    $matches = true;
                }
            }

            $rangeDisplay = $slab['range_display'] ?? ($max !== null
                ? ('Exceeding Rs. ' . number_format($min) . ' up to Rs. ' . number_format($max))
                : ('Exceeding Rs. ' . number_format($min)));

            $formulaDisplay = $slab['formula'] ?? ($rate . '% × monthly taxable regular profits − Rs. ' . number_format($deduction));

            if ($matches) {
                $applicableSlab = [
                    'band' => $bandNum,
                    'name' => $slab['name'] ?? ('Tax Band ' . $bandNum),
                    'min_monthly' => $min,
                    'max_monthly' => $max,
                    'rate' => $rate,
                    'rate_percent' => $rate,
                    'fixed_deduction' => $deduction,
                    'range_display' => $rangeDisplay,
                    'formula' => $formulaDisplay,
                ];
                break;
            }

            if ($max !== null) {
                $slabWidth = $max - $min;
                $cumulativePrior += round($slabWidth * ($rate / 100), 2);
                $currentLower = $max;
            }
        }

        // Fallback to highest bracket if earnings exceed all defined bands
        if (!$applicableSlab && count($slabs) > 0) {
            $lastSlab = end($slabs);
            $bandNum = $lastSlab['band'] ?? count($slabs);
            $rate = (float) ($lastSlab['rate'] ?? 0);
            $minMonthly = isset($lastSlab['min_monthly']) ? (float) $lastSlab['min_monthly'] : $currentLower;
            $deduction = isset($lastSlab['fixed_deduction']) ? (float) $lastSlab['fixed_deduction'] : $cumulativePrior;
            $rangeDisplay = $lastSlab['range_display'] ?? ('Exceeding Rs. ' . number_format($minMonthly));
            $formula = $lastSlab['formula'] ?? ($rate . '% × monthly taxable regular profits − Rs. ' . number_format($deduction));

            $applicableSlab = [
                'band' => $bandNum,
                'name' => $lastSlab['name'] ?? ('Tax Band ' . $bandNum),
                'min_monthly' => $minMonthly,
                'max_monthly' => null,
                'rate' => $rate,
                'rate_percent' => $rate,
                'fixed_deduction' => $deduction,
                'range_display' => $rangeDisplay,
                'formula' => $formula,
            ];
        }

        $rate = (float) ($applicableSlab['rate'] ?? 0);
        $deduction = (float) ($applicableSlab['fixed_deduction'] ?? 0);
        $totalTax = max(0, round(($taxableEarnings * ($rate / 100)) - $deduction, 2));

        $breakdown = [
            [
                'band' => $applicableSlab['band'] ?? 1,
                'name' => $applicableSlab['name'] ?? 'Band 1',
                'rate' => $rate,
                'rate_percent' => $rate,
                'range_display' => $applicableSlab['range_display'] ?? '',
                'formula' => $applicableSlab['formula'] ?? '',
                'fixed_deduction' => $deduction,
                'taxable_portion' => $taxableEarnings,
                'taxable_in_band' => $taxableEarnings,
                'tax_amount' => $totalTax,
                'tax_in_band' => $totalTax,
            ]
        ];

        return [
            'tax_table_id' => $taxTable->id,
            'tax_table_name' => $taxTable->name,
            'tax_table_code' => $taxTable->code,
            'personal_relief_monthly' => $relief,
            'taxable_amount' => round(max(0, $taxableEarnings - $relief), 2),
            'taxable_remuneration' => round($taxableEarnings, 2),
            'paye_amount' => $totalTax,
            'applicable_band' => $applicableSlab['band'] ?? 1,
            'applicable_range' => $applicableSlab['range_display'] ?? '',
            'applicable_formula' => $applicableSlab['formula'] ?? '',
            'applicable_rate' => $rate,
            'fixed_deduction' => $deduction,
            'band_breakdown' => $breakdown,
            'status' => $totalTax > 0 ? 'Tax applied' : 'Below threshold',
        ];
    }

    /**
     * Compute official Inland Revenue range table with ranges, rates, formulas, and fixed deductions.
     */
    public function getRangeTable(): array
    {
        $relief = (float) $this->personal_relief_monthly;
        $ranges = [];

        // Band 0: Personal Relief / Tax-Free Allowance
        $ranges[] = [
            'band' => 0,
            'name' => 'Tax Relief',
            'min_monthly' => 0.0,
            'max_monthly' => $relief,
            'range_display' => 'Up to Rs. ' . number_format($relief),
            'rate_percent' => 0.0,
            'rate_display' => 'Nil',
            'formula' => 'Tax relief – Nil',
            'calculation_display' => 'Tax relief – Nil',
            'fixed_deduction' => 0.0,
            'fixed_deduction_display' => 'Rs. 0.00',
        ];

        $currentLower = $relief;
        $cumulativePrior = 0.0;
        $slabs = is_array($this->slabs) ? $this->slabs : [];

        foreach ($slabs as $idx => $slab) {
            $bandNum = $slab['band'] ?? ($idx + 1);
            $rate = (float) ($slab['rate'] ?? 0);

            if (isset($slab['min_monthly'])) {
                $min = (float) $slab['min_monthly'];
            } else {
                $min = $currentLower;
            }

            if (isset($slab['max_monthly'])) {
                $max = $slab['max_monthly'] !== null ? (float) $slab['max_monthly'] : null;
            } elseif (isset($slab['threshold']) && is_numeric($slab['threshold']) && (float) $slab['threshold'] > 0) {
                $max = round($min + (float) $slab['threshold'], 2);
            } else {
                $max = null;
            }

            if (isset($slab['fixed_deduction'])) {
                $fixedDeduction = (float) $slab['fixed_deduction'];
            } else {
                $fixedDeduction = round(($min * ($rate / 100)) - $cumulativePrior, 2);
            }

            $rangeDisplay = $slab['range_display'] ?? ($max !== null
                ? ('Exceeding Rs. ' . number_format($min) . ' up to Rs. ' . number_format($max))
                : ('Exceeding Rs. ' . number_format($min)));

            $formulaDisplay = $slab['formula'] ?? ($rate . '% × monthly taxable regular profits − Rs. ' . number_format($fixedDeduction));

            $ranges[] = [
                'band' => $bandNum,
                'name' => $slab['name'] ?? ('Tax Band ' . $bandNum),
                'min_monthly' => $min,
                'max_monthly' => $max,
                'range_display' => $rangeDisplay,
                'rate_percent' => $rate,
                'rate_display' => $rate . '%',
                'formula' => $formulaDisplay,
                'calculation_display' => $formulaDisplay,
                'fixed_deduction' => $fixedDeduction,
                'fixed_deduction_display' => 'Rs. ' . number_format($fixedDeduction, 2),
            ];

            if ($max !== null) {
                $slabWidth = $max - $min;
                $cumulativePrior += round($slabWidth * ($rate / 100), 2);
                $currentLower = $max;
            }
        }

        return $ranges;
    }
}

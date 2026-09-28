<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Late grace NoPay (per-company add-on `late_grace_nopay`).
 *
 * Each day, minutes late beyond (start time + grace) are added up for the month.
 * Every full block of `block_minutes` becomes `days_per_block` NoPay day(s).
 * Example (07:15 start, 15 min grace, 30 min block): in at 07:40 → 10 excess minutes;
 * once the month's excess reaches 30 minutes the employee gets 1 NoPay day, at 60 → 2, …
 */
class LateGraceNoPayService
{
    public const DEFAULTS = [
        'start_time' => '07:15',
        'grace_minutes' => 15,
        'block_minutes' => 30,
        'days_per_block' => 1,
        'deduct_from' => 'bonus',
    ];

    public static function config($company): array
    {
        $cfg = CompanyProcessSettings::packConfig($company, 'late_grace_nopay') + self::DEFAULTS;

        $start = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $cfg['start_time'])
            ? (string) $cfg['start_time']
            : self::DEFAULTS['start_time'];

        return [
            'start_time' => $start,
            'grace_minutes' => max(0, (int) $cfg['grace_minutes']),
            'block_minutes' => max(1, (int) $cfg['block_minutes']),
            'days_per_block' => max(0, (float) $cfg['days_per_block']),
            'deduct_from' => $cfg['deduct_from'] === 'basic' ? 'basic' : 'bonus',
        ];
    }

    /**
     * @return array{days: float, excess_minutes: int, late_days: int, amount: float, per_day: float,
     *               deduct_from: string, config: array, days_detail: array}
     */
    public function monthForEmployee(
        int $employeeId,
        int $year,
        int $month,
        array $config,
        float $basicSalary,
        float $monthlyBonus,
        float $nopayWorkingDays
    ): array {
        $from = Carbon::create($year, $month, 1)->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $firstIns = DB::table('time_cards')
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$from, $to])
            ->where(function ($q) {
                $q->where('entry', 1)->orWhereRaw('LOWER(status) IN (?, ?)', ['in', 'late coming']);
            })
            ->whereNull('deleted_at')
            ->groupBy('date')
            ->selectRaw('date, MIN(time) AS first_in')
            ->orderBy('date')
            ->get();

        [$startHour, $startMinute] = array_map('intval', explode(':', $config['start_time']));
        $allowedMinute = $startHour * 60 + $startMinute + $config['grace_minutes'];

        $excessTotal = 0;
        $detail = [];
        foreach ($firstIns as $row) {
            $in = Carbon::parse($row->first_in);
            $inMinute = $in->hour * 60 + $in->minute;
            $excess = $inMinute - $allowedMinute;
            if ($excess <= 0) {
                continue;
            }
            $excessTotal += $excess;
            $detail[] = [
                'date' => $row->date,
                'in_time' => $in->format('H:i'),
                'excess_minutes' => $excess,
                'running_minutes' => $excessTotal,
            ];
        }

        $blocks = intdiv($excessTotal, $config['block_minutes']);
        $days = round($blocks * $config['days_per_block'], 4);
        $perDay = ($basicSalary + $monthlyBonus) / max(1.0, $nopayWorkingDays);
        $amount = round($days * $perDay, 2);

        return [
            'days' => $days,
            'excess_minutes' => $excessTotal,
            'late_days' => count($detail),
            'amount' => $amount,
            'per_day' => round($perDay, 4),
            'deduct_from' => $config['deduct_from'],
            'config' => $config,
            'days_detail' => $detail,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\AttendancePunchAlert;
use App\Models\User;
use App\Models\employee;
use App\Models\leave_master;
use App\Models\time_card;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class AttendancePunchAlertService
{
    public function __construct(private RosterShiftResolver $rosterResolver)
    {
    }

    public function run(?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now('Asia/Colombo');
        $sent = 0;
        $skippedLeave = 0;
        if (!Schema::hasTable('time_cards')) {
            return ['sent' => 0, 'skipped_leave' => 0];
        }

        $today = $now->toDateString();
        $yesterday = $now->copy()->subDay()->toDateString();
        $windowMinutes = 25;

        $employees = employee::query()
            ->where('is_active', true)
            ->with('organizationAssignment')
            ->get();

        $onLeave = $this->employeesOnLeave([$yesterday, $today, $now->copy()->addDay()->toDateString()]);

        $cards = time_card::query()
            ->whereNull('deleted_at')
            ->whereIn('date', [$yesterday, $today, $now->copy()->addDay()->toDateString()])
            ->whereIn('status', ['IN', 'Late Coming', 'OUT', 'Early OUT'])
            ->get();

        foreach ($employees as $emp) {
            $assignments = $this->rosterResolver->assignmentsFor($emp, $today);
            if ($assignments->isEmpty()) {
                continue;
            }
            foreach ($assignments as $row) {
                $shift = $row['shift'];
                $windows = [];
                foreach ([$yesterday, $today] as $d) {
                    [$start, $end] = $this->rosterResolver->window($d, $shift);
                    $windows[] = [$start, $end];
                }
                foreach ($windows as [$start, $end]) {
                    $workDate = $start->toDateString();
                    if ($this->isOnLeave($onLeave, (int) $emp->id, $workDate)) {
                        $skippedLeave++;
                        continue;
                    }

                    $empCards = $cards->where('employee_id', $emp->id);
                    if ($now->gte($start) && $now->lte($start->copy()->addMinutes($windowMinutes))) {
                        if (!$this->hasPunch($empCards, $start->copy()->subHours(3), $end->copy()->addHour(), ['IN', 'Late Coming'])) {
                            if ($this->dispatch($emp, $shift->id, $workDate, 'missed_in', $start, $end)) {
                                $sent++;
                            }
                        }
                    }
                    if ($now->gte($end) && $now->lte($end->copy()->addMinutes($windowMinutes))) {
                        if (!$this->hasPunch($empCards, $start, $end->copy()->addHours(4), ['OUT', 'Early OUT'])) {
                            if ($this->dispatch($emp, $shift->id, $workDate, 'missed_out', $start, $end)) {
                                $sent++;
                            }
                        }
                    }
                }
            }
        }

        return ['sent' => $sent, 'skipped_leave' => $skippedLeave];
    }

    private function employeesOnLeave(array $dates): array
    {
        if (!Schema::hasTable('leave_masters')) {
            return [];
        }
        $from = min($dates);
        $to = max($dates);
        $approved = ['Approved', 'approved', 'HR_Approved', 'Supervisor_Approved', 'SUPERVISOR_APPROVED'];
        $rows = leave_master::query()
            ->whereIn('status', $approved)
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($range) use ($from, $to) {
                    $range->whereDate('leave_from', '<=', $to)
                        ->whereDate('leave_to', '>=', $from);
                });
                if (Schema::hasColumn('leave_masters', 'leave_date')) {
                    $q->orWhereBetween('leave_date', [$from, $to]);
                }
            })
            ->get(['employee_id', 'leave_from', 'leave_to', 'leave_date']);

        $map = [];
        foreach ($rows as $row) {
            $start = $row->leave_from ?: $row->leave_date;
            $end = $row->leave_to ?: $row->leave_from ?: $row->leave_date;
            if (!$start) {
                continue;
            }
            $cursor = Carbon::parse($start)->startOfDay();
            $last = Carbon::parse($end)->startOfDay();
            while ($cursor->lte($last)) {
                $map[(int) $row->employee_id][$cursor->toDateString()] = true;
                $cursor->addDay();
            }
        }

        return $map;
    }

    private function isOnLeave(array $onLeave, int $employeeId, string $date): bool
    {
        return !empty($onLeave[$employeeId][$date]);
    }

    private function hasPunch($cards, Carbon $from, Carbon $to, array $statuses): bool
    {
        foreach ($cards as $card) {
            if (!in_array($card->status, $statuses, true)) {
                continue;
            }
            $at = Carbon::parse($card->date.' '.$card->time, 'Asia/Colombo');
            if ($at->between($from, $to)) {
                return true;
            }
        }

        return false;
    }

    private function dispatch(employee $emp, $shiftId, string $workDate, string $kind, Carbon $start, Carbon $end): bool
    {
        if (Schema::hasTable('attendance_punch_alerts')) {
            $existing = AttendancePunchAlert::where('employee_id', $emp->id)
                ->whereDate('work_date', $workDate)
                ->where('kind', $kind)
                ->where('shift_id', $shiftId)
                ->exists();
            if ($existing) {
                return false;
            }
            AttendancePunchAlert::create([
                'employee_id' => $emp->id,
                'work_date' => $workDate,
                'kind' => $kind,
                'shift_id' => $shiftId,
                'sent_at' => now('Asia/Colombo'),
            ]);
        }

        $name = $emp->full_name ?: $emp->name_with_initials ?: 'Employee';
        $no = $emp->attendance_employee_no ?: $emp->id;
        if ($kind === 'missed_in') {
            $empTitle = 'Fingerprint IN missing';
            $empBody = "Your IN fingerprint is not in the system after shift start ({$start->format('H:i')}). Punch on the fingerprint device or mobile now. HR has been notified.";
            $hrTitle = 'Missed fingerprint IN';
            $hrBody = "Advise immediately: {$name} ({$no}) has no IN punch after shift start {$start->format('H:i')}. Check the device or mobile punch.";
        } else {
            $empTitle = 'Fingerprint OUT missing';
            $empBody = "Your OUT fingerprint is not in the system after shift end ({$end->format('H:i')}). Punch on the fingerprint device or mobile now. HR has been notified.";
            $hrTitle = 'Missed fingerprint OUT';
            $hrBody = "Advise immediately: {$name} ({$no}) has no OUT punch after shift end {$end->format('H:i')}. Check the device or mobile punch.";
        }

        LeaveNotificationService::notifyEmployee((int) $emp->id, $empTitle, $empBody, [
            'type' => 'missed_punch',
            'kind' => $kind,
        ]);

        $empUserIds = User::where('employee_id', $emp->id)->pluck('id')->all();
        $hrUserIds = User::whereIn('role', ['hr', 'admin'])->pluck('id')->all();
        foreach ($hrUserIds as $userId) {
            if (Schema::hasTable('notifications')) {
                \App\Models\Notification::create([
                    'user_id' => $userId,
                    'type' => 'missed_punch',
                    'title' => $hrTitle,
                    'message' => $hrBody,
                    'data' => ['employee_id' => $emp->id, 'kind' => $kind],
                    'is_read' => false,
                ]);
            }
        }

        $fcm = app(FcmPushService::class);
        $fcm->sendToUsers($empUserIds, $empTitle, $empBody, ['type' => 'missed_punch', 'kind' => $kind]);
        $fcm->sendToUsers($hrUserIds, $hrTitle, $hrBody, ['type' => 'missed_punch', 'kind' => $kind]);

        return true;
    }
}

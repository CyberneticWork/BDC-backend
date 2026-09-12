<?php

namespace App\Services;

use App\Models\time_card;
use App\Models\time_card_audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class TimeCardAuditService
{
    public static function snapshot(?time_card $card): ?array
    {
        if (!$card) {
            return null;
        }

        return [
            'date' => $card->date,
            'time' => $card->time,
            'status' => $card->status,
            'entry' => $card->entry,
            'approval_status' => $card->approval_status,
            'working_hours' => $card->working_hours,
            'actual_date' => $card->actual_date,
            'entry_source' => $card->entry_source ?? null,
        ];
    }

    public static function log(
        ?time_card $card,
        string $action,
        ?string $reason = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $source = null,
    ): void {
        if (!Schema::hasTable('time_card_audits')) {
            return;
        }

        time_card_audit::create([
            'time_card_id' => $card?->id,
            'employee_id' => $card?->employee_id,
            'entry_date' => $card?->date,
            'action' => $action,
            'source' => $source ?? ($card?->entry_source ?? 'manual'),
            'reason' => $reason,
            'old_values' => $oldValues,
            'new_values' => $newValues ?? self::snapshot($card),
            'user_id' => Auth::id(),
        ]);
    }
}

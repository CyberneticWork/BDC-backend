<?php

namespace App\Services;

use App\Models\User;
use App\Models\employee;

class EmployeeUserLinker
{
    /**
     * Give this employee a login. If email or NIC already belongs to an HR /
     * manager account, attach the employee profile to that same password.
     * Otherwise create a portal-only user (password = NIC).
     *
     * @return array{user: User, created: bool, linked_existing: bool}
     */
    public function ensureLogin(employee $employee, string $email, string $nic, string $name): array
    {
        $existing = $this->findUserByEmailOrNic($email, $nic);

        if ($existing) {
            $this->transferEmployeeLink($existing, $employee);
            if (! $existing->nic && $nic !== '') {
                $existing->nic = $nic;
            }
            $existing->save();

            return [
                'user' => $existing,
                'created' => false,
                'linked_existing' => true,
            ];
        }

        $user = User::create([
            'name' => $name !== '' ? $name : $employee->full_name,
            'email' => $email,
            'nic' => $nic,
            'employee_id' => $employee->id,
            'password' => $nic,
            'role' => 'employee',
        ]);

        return [
            'user' => $user,
            'created' => true,
            'linked_existing' => false,
        ];
    }

    public function findUserByEmailOrNic(string $email, string $nic): ?User
    {
        $email = strtolower(trim($email));
        $nicRaw = trim($nic);
        $nicNorm = $this->normalizeNic($nicRaw);

        if ($email !== '') {
            $byEmail = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($byEmail) {
                return $byEmail;
            }
        }

        if ($nicRaw === '') {
            return null;
        }

        return User::query()
            ->where(function ($query) use ($nicRaw, $nicNorm) {
                $query->whereRaw('LOWER(nic) = ?', [strtolower($nicRaw)]);
                if ($nicNorm !== '') {
                    $query->orWhereRaw(
                        "UPPER(REPLACE(REPLACE(nic, '-', ''), ' ', '')) = ?",
                        [$nicNorm]
                    );
                }
            })
            ->first();
    }

    public function findEmployeeByLink(string $link): ?employee
    {
        $link = trim($link);
        if ($link === '') {
            return null;
        }
        $nicNorm = $this->normalizeNic($link);

        return employee::query()
            ->where(function ($query) use ($link, $nicNorm) {
                $query->where('attendance_employee_no', $link)
                    ->orWhere('epf', $link)
                    ->orWhere('nic', $link);
                if ($nicNorm !== '') {
                    $query->orWhereRaw(
                        "UPPER(REPLACE(REPLACE(nic, '-', ''), ' ', '')) = ?",
                        [$nicNorm]
                    );
                }
            })
            ->first();
    }

    public function linkUserToEmployee(User $user, string $link): void
    {
        $employee = $this->findEmployeeByLink($link);
        if (! $employee) {
            abort(422, 'No employee found for that attendance number, EPF or NIC.');
        }

        $this->transferEmployeeLink($user, $employee);
        if (! $user->nic && $employee->nic) {
            $user->nic = $employee->nic;
        }
        $user->save();
    }

    private function transferEmployeeLink(User $owner, employee $employee): void
    {
        $taken = User::query()
            ->where('employee_id', $employee->id)
            ->where('id', '!=', $owner->id)
            ->get();

        foreach ($taken as $other) {
            $otherRole = strtolower((string) $other->role);
            $other->employee_id = null;
            $other->save();
            if ($otherRole === 'employee') {
                $other->delete();
            }
        }

        $owner->employee_id = $employee->id;
    }

    private function normalizeNic(string $value): string
    {
        return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $value) ?? '');
    }
}

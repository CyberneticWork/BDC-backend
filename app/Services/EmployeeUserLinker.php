<?php

namespace App\Services;

use App\Models\User;
use App\Models\employee;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        $email = strtolower(trim($email));
        $nic = trim($nic);

        if ($email === '' && $nic === '') {
            throw new HttpException(422, 'Employee email or NIC is required to create a login.');
        }

        $existing = $this->findUserByEmailOrNic($email, $nic);

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $this->transferEmployeeLink($existing, $employee);
            if ($nic !== '' && ! $existing->nic) {
                $existing->nic = $nic;
            }
            if ($name !== '') {
                $existing->name = $name;
            }
            if ($email !== '' && strtolower((string) $existing->email) !== $email) {
                // Keep existing login email when linking an HR account; only fill blank emails.
                if (trim((string) $existing->email) === '') {
                    $existing->email = $email;
                }
            }
            $existing->save();

            return [
                'user' => $existing,
                'created' => false,
                'linked_existing' => true,
            ];
        }

        try {
            $payload = [
                'name' => $name !== '' ? $name : ($employee->full_name ?: 'Employee'),
                'email' => $email !== '' ? $email : $this->fallbackEmail($employee, $nic),
                'nic' => $nic !== '' ? $nic : null,
                'employee_id' => $employee->id,
                'password' => $nic !== '' ? $nic : bin2hex(random_bytes(8)),
                'role' => 'employee',
            ];

            $user = User::create($payload);
        } catch (QueryException $e) {
            // Soft-deleted rows still occupy unique email/nic — reclaim and link.
            $recovered = $this->findUserByEmailOrNic($email, $nic, true);
            if ($recovered) {
                if ($recovered->trashed()) {
                    $recovered->restore();
                }
                $this->transferEmployeeLink($recovered, $employee);
                $recovered->save();

                return [
                    'user' => $recovered,
                    'created' => false,
                    'linked_existing' => true,
                ];
            }

            throw new HttpException(
                422,
                'Could not create employee login: email or NIC is already used by another account.'
            );
        }

        return [
            'user' => $user,
            'created' => true,
            'linked_existing' => false,
        ];
    }

    public function findUserByEmailOrNic(string $email, string $nic, bool $withTrashed = false): ?User
    {
        $email = strtolower(trim($email));
        $nicRaw = trim($nic);
        $nicNorm = $this->normalizeNic($nicRaw);

        $query = $withTrashed ? User::withTrashed() : User::query();

        if ($email !== '') {
            $byEmail = (clone $query)->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($byEmail) {
                return $byEmail;
            }
        }

        if ($nicRaw === '') {
            return null;
        }

        return (clone $query)
            ->where(function ($q) use ($nicRaw, $nicNorm) {
                $q->whereRaw('LOWER(nic) = ?', [strtolower($nicRaw)]);
                if ($nicNorm !== '') {
                    $q->orWhereRaw(
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
        $taken = User::withTrashed()
            ->where('employee_id', $employee->id)
            ->where('id', '!=', $owner->id)
            ->get();

        foreach ($taken as $other) {
            $otherRole = strtolower((string) $other->role);
            $other->employee_id = null;
            $other->save();
            // Free unique email/nic for portal-only accounts so a later create does not 500
            if ($otherRole === 'employee') {
                try {
                    $other->forceDelete();
                } catch (\Throwable) {
                    $other->delete();
                }
            }
        }

        $owner->employee_id = $employee->id;
    }

    private function fallbackEmail(employee $employee, string $nic): string
    {
        $base = $nic !== '' ? $this->normalizeNic($nic) : ('emp'.$employee->id);

        return strtolower($base).'@employee.local';
    }

    private function normalizeNic(string $value): string
    {
        return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $value) ?? '');
    }
}

const fs = require('fs');
const path = 'app/Http/Controllers/TimeCardController.php';
let text = fs.readFileSync(path, 'utf8');

// Patch store() create block - first occurrence of time_card::create in active class
const createSnippet = `        $timeCard = time_card::create([
            'employee_id' => $employee->id,
            'time' => $storeTime,
            'date' => $validated['date'],
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date,
        ]);`;

const createReplacement = `        $needsApproval = in_array($status, ['Late Coming', 'Early OUT'], true);

        $timeCard = time_card::create([
            'employee_id' => $employee->id,
            'time' => $storeTime,
            'date' => $validated['date'],
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'approval_status' => $needsApproval ? 'Pending' : 'Active',
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date,
        ]);`;

if (!text.includes(createSnippet)) {
  console.log('store create snippet not found');
} else {
  text = text.replace(createSnippet, createReplacement);
  console.log('patched store create');
}

const attendSnippet = `        $timeCard = time_card::create([
            'employee_id' => $employee->id,
            'time' => $storeTime,
            'date' => $request->date,
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date,
        ]);`;

const attendReplacement = `        $needsApproval = in_array($status, ['Late Coming', 'Early OUT'], true);

        $timeCard = time_card::create([
            'employee_id' => $employee->id,
            'time' => $storeTime,
            'date' => $request->date,
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'approval_status' => $needsApproval ? 'Pending' : 'Active',
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date,
        ]);`;

if (!text.includes(attendSnippet)) {
  console.log('attendance create snippet not found');
} else {
  text = text.replace(attendSnippet, attendReplacement);
  console.log('patched attendance create');
}

fs.writeFileSync(path, text);

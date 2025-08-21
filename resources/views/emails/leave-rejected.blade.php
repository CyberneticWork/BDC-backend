<!DOCTYPE html>
<html>
<head>
    <title>Leave Rejected</title>
</head>
<body>
    <h2>Leave Request Rejected</h2>
    <p>Hello {{ $employee->full_name }},</p>
    <p>Your leave request has been rejected.</p>
    
    @if($rejectionReason)
    <p><strong>Reason for rejection:</strong> {{ $rejectionReason }}</p>
    @endif
    
    <p><strong>Leave Details:</strong></p>
    <ul>
        <li>Type: {{ $leave->leave_type }}</li>
        <li>From: {{ $leave->leave_from }}</li>
        <li>To: {{ $leave->leave_to }}</li>
        <li>Reason: {{ $leave->reason }}</li>
        <li>Status: {{ $leave->status }}</li>
    </ul>
    
    <p>Please contact HR for more information.</p>
</body>
</html>
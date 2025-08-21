<!DOCTYPE html>
<html>
<head>
    <title>Leave Approved</title>
</head>
<body>
    <h2>Leave Request Approved</h2>
    <p>Hello {{ $employee->full_name }},</p>
    <p>Your leave request has been approved.</p>
    
    <p><strong>Leave Details:</strong></p>
    <ul>
        <li>Type: {{ $leave->leave_type }}</li>
        <li>From: {{ $leave->leave_from }}</li>
        <li>To: {{ $leave->leave_to }}</li>
        <li>Reason: {{ $leave->reason }}</li>
        <li>Status: {{ $leave->status }}</li>
    </ul>
    
    <p>Thank you!</p>
</body>
</html>
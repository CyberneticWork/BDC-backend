<!DOCTYPE html>
<html>

<head>
    <title>Leave Request Rejected</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
        }

        .status-rejected {
            color: #dc3545;
            font-weight: bold;
        }

        .details {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }

        .details-list {
            list-style-type: none;
            padding-left: 0;
        }

        .details-list li {
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }

        .details-list li:last-child {
            border-bottom: none;
        }

        .footer {
            margin-top: 30px;
            font-size: 0.9em;
            color: #6c757d;
        }

        .rejection-reason {
            background-color: #fff5f5;
            padding: 15px;
            border-left: 4px solid #dc3545;
            margin: 15px 0;
        }
    </style>
</head>

<body>
    <div class="header">
        <h2>Leave Request Rejected</h2>
    </div>

    <p>Hello {{ $employee->full_name }},</p>

    <p>We regret to inform you that your leave request has been <span class="status-rejected">rejected</span>.</p>

    @if ($rejectionReason)
        <div class="rejection-reason">
            <p><strong>Reason for rejection:</strong> {{ $rejectionReason }}</p>
        </div>
    @endif

    <div class="details">
        <h3>Leave Details</h3>
        <ul class="details-list">
            <li><strong>Type:</strong> {{ $leave->leave_type }}</li>
            @if (is_null($leave->leave_from) && is_null($leave->leave_to))
                <li><strong>Date:</strong> {{ $leave->leave_date }}</li>
            @else
                <li><strong>From:</strong> {{ $leave->leave_from }}</li>
                <li><strong>To:</strong> {{ $leave->leave_to }}</li>
            @endif
            <li><strong>Reason:</strong> {{ $leave->reason }}</li>
            <li><strong>Current Status:</strong> <span class="status-rejected">Rejected</span></li>
        </ul>
    </div>

    <div class="footer">
        <p>If you have any questions regarding this decision, please contact the HR department for clarification.</p>
    </div>
</body>

</html>

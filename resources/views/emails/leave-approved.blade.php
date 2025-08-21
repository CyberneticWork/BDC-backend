<!DOCTYPE html>
<html>

<head>
    <title>Leave Request Update</title>
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
            border-left: 4px solid {{ $leave->status === 'Approved' ? '#28a745' : ($leave->status === 'HR_Approved' ? '#17a2b8' : '#6c757d') }};
        }

        .status-approved {
            color: #28a745;
            font-weight: bold;
        }

        .status-hr-approved {
            color: #17a2b8;
            font-weight: bold;
        }

        .status-other {
            color: #6c757d;
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
    </style>
</head>

<body>
    <div class="header">
        @if ($leave->status === 'HR_Approved')
            <h2>Leave Request In Progress</h2>
        @elseif($leave->status === 'Approved')
            <h2>Leave Request Approved</h2>
        @else
            <h2>Leave Request Update</h2>
        @endif
    </div>

    <p>Hello {{ $employee->full_name }},</p>

    @if ($leave->status === 'HR_Approved')
        <p>Your leave request has been <span class="status-hr-approved">Approved by HR</span> and is now pending final
            approval.</p>
    @elseif($leave->status === 'Approved')
        <p>Great news! Your leave request has been <span class="status-approved">approved</span> by the HR Department.
        </p>
    @else
        <p>Your leave request status: <span class="status-other">{{ $leave->status }}</span></p>
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
            <li><strong>Current Status:</strong>
                @if ($leave->status === 'Approved')
                    <span class="status-approved">Approved</span>
                @elseif($leave->status === 'HR_Approved')
                    <span class="status-hr-approved">HR Approved</span>
                @else
                    <span class="status-other">{{ $leave->status }}</span>
                @endif
            </li>
        </ul>
    </div>

    <div class="footer">
        <p>Thank you for using our HR system. If you have any questions, please contact the HR department.</p>
    </div>
</body>

</html>

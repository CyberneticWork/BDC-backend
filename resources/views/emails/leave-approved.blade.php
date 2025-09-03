<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Request Update</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #2c3e50;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 650px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            position: relative;
        }

        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }

        .header::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 20px solid transparent;
            border-right: 20px solid transparent;
            border-top: 10px solid #764ba2;
        }

        .header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 8px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 10px;
        }

        .status-approved {
            background: rgba(40, 167, 69, 0.2);
            color: #155724;
            border: 2px solid #28a745;
        }

        .status-hr-approved {
            background: rgba(23, 162, 184, 0.2);
            color: #0c5460;
            border: 2px solid #17a2b8;
        }

        .status-pending {
            background: rgba(255, 193, 7, 0.2);
            color: #856404;
            border: 2px solid #ffc107;
        }

        .status-rejected {
            background: rgba(220, 53, 69, 0.2);
            color: #721c24;
            border: 2px solid #dc3545;
        }

        .status-other {
            background: rgba(108, 117, 125, 0.2);
            color: #495057;
            border: 2px solid #6c757d;
        }

        .content {
            padding: 40px 30px;
        }

        .greeting {
            font-size: 1.1rem;
            margin-bottom: 25px;
            color: #2c3e50;
        }

        .greeting strong {
            color: #667eea;
        }

        .message {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            border-left: 4px solid #667eea;
            font-size: 1rem;
            line-height: 1.7;
        }

        .details-card {
            background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .details-card h3 {
            color: #2c3e50;
            font-size: 1.3rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .details-card h3::before {
            content: '📋';
            font-size: 1.2rem;
        }

        .details-grid {
            display: grid;
            gap: 15px;
        }

        .detail-item {
            display: flex;
            align-items: flex-start;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: #495057;
            min-width: 120px;
            margin-right: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-value {
            flex: 1;
            color: #2c3e50;
        }

        .icon {
            width: 16px;
            height: 16px;
            opacity: 0.7;
        }

        .footer {
            background: #f8f9fa;
            padding: 25px 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
            color: #6c757d;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .contact-info {
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            border: 1px solid #e9ecef;
        }

        .contact-info strong {
            color: #667eea;
        }

        /* Status icons */
        .status-icon {
            width: 20px;
            height: 20px;
            display: inline-block;
        }

        /* Responsive design */
        @media (max-width: 600px) {
            body {
                padding: 10px;
            }

            .container {
                margin: 10px auto;
                border-radius: 12px;
            }

            .header {
                padding: 25px 20px;
            }

            .header h1 {
                font-size: 1.5rem;
            }

            .content {
                padding: 30px 20px;
            }

            .details-card {
                padding: 20px;
            }

            .detail-label {
                min-width: 100px;
                font-size: 0.9rem;
            }

            .footer {
                padding: 20px;
            }
        }

        /* Animation for status badge */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .status-badge {
            animation: fadeInUp 0.6s ease-out;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            @if ($leave->status === 'HR_Approved')
                <h1>🔄 Leave Request In Progress</h1>
                <div class="status-badge status-hr-approved">
                    <span class="status-icon">✅</span>
                    HR Approved - Pending Final Review
                </div>
            @elseif($leave->status === 'Approved')
                <h1>🎉 Leave Request Approved</h1>
                <div class="status-badge status-approved">
                    <span class="status-icon">✅</span>
                    Fully Approved
                </div>
            @elseif($leave->status === 'Rejected')
                <h1>❌ Leave Request Update</h1>
                <div class="status-badge status-rejected">
                    <span class="status-icon">❌</span>
                    Request Declined
                </div>
            @elseif($leave->status === 'Pending')
                <h1>⏳ Leave Request Update</h1>
                <div class="status-badge status-pending">
                    <span class="status-icon">⏳</span>
                    Under Review
                </div>
            @else
                <h1>📋 Leave Request Update</h1>
                <div class="status-badge status-other">
                    <span class="status-icon">📋</span>
                    {{ $leave->status }}
                </div>
            @endif
        </div>

        <div class="content">
            <div class="greeting">
                Hello <strong>{{ $employee->full_name }}</strong>,
            </div>

            <div class="message">
                @if ($leave->status === 'HR_Approved')
                    🎯 Great progress! Your leave request has been <strong style="color: #17a2b8;">approved by HR</strong> and is now awaiting final management approval. We'll notify you once the process is complete.
                @elseif($leave->status === 'Approved')
                    🎉 Excellent news! Your leave request has been <strong style="color: #28a745;">fully approved</strong> by our HR Department. You can proceed with your planned time off as requested.
                @elseif($leave->status === 'Rejected')
                    😔 We regret to inform you that your leave request has been <strong style="color: #dc3545;">declined</strong>. Please contact HR for more information about this decision.
                @elseif($leave->status === 'Pending')
                    ⏳ Your leave request is currently <strong style="color: #ffc107;">under review</strong> by our HR team. We'll update you as soon as a decision is made.
                @else
                    📋 Your leave request status has been updated to: <strong style="color: #6c757d;">{{ $leave->status }}</strong>
                @endif
            </div>

            <div class="details-card">
                <h3>Leave Request Details</h3>
                <div class="details-grid">
                    <div class="detail-item">
                        <div class="detail-label">
                            <span>📝</span>
                            Type:
                        </div>
                        <div class="detail-value">
                            <strong>{{ $leave->leave_type }}</strong>
                        </div>
                    </div>

                    @if (is_null($leave->leave_from) && is_null($leave->leave_to))
                        <div class="detail-item">
                            <div class="detail-label">
                                <span>📅</span>
                                Date:
                            </div>
                            <div class="detail-value">
                                {{ $leave->leave_date }}
                            </div>
                        </div>
                    @else
                        <div class="detail-item">
                            <div class="detail-label">
                                <span>📅</span>
                                From:
                            </div>
                            <div class="detail-value">
                                {{ $leave->leave_from }}
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">
                                <span>📅</span>
                                To:
                            </div>
                            <div class="detail-value">
                                {{ $leave->leave_to }}
                            </div>
                        </div>
                    @endif

                    <div class="detail-item">
                        <div class="detail-label">
                            <span>💬</span>
                            Reason:
                        </div>
                        <div class="detail-value">
                            {{ $leave->reason }}
                        </div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">
                            <span>🏷️</span>
                            Status:
                        </div>
                        <div class="detail-value">
                            @if ($leave->status === 'Approved')
                                <span class="status-approved" style="padding: 4px 12px; border-radius: 15px; font-size: 0.9rem;">✅ Approved</span>
                            @elseif($leave->status === 'HR_Approved')
                                <span class="status-hr-approved" style="padding: 4px 12px; border-radius: 15px; font-size: 0.9rem;">✅ HR Approved</span>
                            @elseif($leave->status === 'Rejected')
                                <span class="status-rejected" style="padding: 4px 12px; border-radius: 15px; font-size: 0.9rem;">❌ Rejected</span>
                            @elseif($leave->status === 'Pending')
                                <span class="status-pending" style="padding: 4px 12px; border-radius: 15px; font-size: 0.9rem;">⏳ Pending</span>
                            @else
                                <span class="status-other" style="padding: 4px 12px; border-radius: 15px; font-size: 0.9rem;">📋 {{ $leave->status }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer">
            <p>💼 Thank you for using our HR Management System</p>
            <div class="contact-info">
                <p>Need assistance? Contact our <strong>HR Department</strong></p>
                <p>📧 hr@company.com | 📞 +1 (555) 123-4567</p>
                <p>🕒 Available Monday - Friday, 9:00 AM - 5:00 PM</p>
            </div>
        </div>
    </div>
</body>
</html>

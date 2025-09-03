<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Request Decision</title>
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
            background: linear-gradient(135deg, #ffeaa7 0%, #fab1a0 50%, #fd79a8 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 650px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
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
            background: linear-gradient(90deg, #e17055 0%, #d63031 100%);
        }

        .header {
            background: linear-gradient(135deg, #e17055 0%, #d63031 100%);
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
            border-top: 10px solid #d63031;
        }

        .header-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.9;
        }

        .header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 8px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .header-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            font-weight: 300;
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
            color: #d63031;
        }

        .main-message {
            background: linear-gradient(145deg, #fff5f5 0%, #ffe8e8 100%);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            border-left: 4px solid #d63031;
            position: relative;
        }

        .main-message::before {
            content: '⚠️';
            position: absolute;
            top: -10px;
            left: 20px;
            background: #fff;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 1.2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .main-message-content {
            font-size: 1.05rem;
            line-height: 1.7;
            margin-top: 10px;
        }

        .rejection-reason {
            background: linear-gradient(145deg, #ffffff 0%, #fff8f8 100%);
            border: 2px solid #fecaca;
            border-radius: 12px;
            padding: 25px;
            margin: 25px 0;
            position: relative;
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.1);
        }

        .rejection-reason::before {
            content: '📋';
            position: absolute;
            top: -12px;
            left: 25px;
            background: #fff;
            padding: 8px 12px;
            border-radius: 50%;
            font-size: 1.1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .rejection-reason h4 {
            color: #991b1b;
            font-size: 1.1rem;
            margin-bottom: 15px;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .rejection-text {
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid #ef4444;
            font-style: italic;
            color: #374151;
            line-height: 1.6;
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
            content: '📄';
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

        .status-rejected {
            background: linear-gradient(135deg, #fecaca 0%, #f87171 100%);
            color: #991b1b;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .support-section {
            background: linear-gradient(145deg, #f0f9ff 0%, #e0f2fe 100%);
            padding: 25px;
            border-radius: 12px;
            margin: 25px 0;
            border: 1px solid #bae6fd;
            position: relative;
        }

        .support-section::before {
            content: '🤝';
            position: absolute;
            top: -12px;
            left: 25px;
            background: #fff;
            padding: 8px 12px;
            border-radius: 50%;
            font-size: 1.1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .support-section h4 {
            color: #0369a1;
            margin-bottom: 15px;
            margin-top: 5px;
            font-size: 1.1rem;
        }

        .support-content {
            color: #374151;
            line-height: 1.6;
        }

        .next-steps {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            border-left: 3px solid #3b82f6;
            margin-top: 15px;
        }

        .next-steps ul {
            margin: 10px 0;
            padding-left: 20px;
        }

        .next-steps li {
            margin: 8px 0;
            color: #374151;
        }

        .footer {
            background: linear-gradient(145deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
            color: #6c757d;
        }

        .contact-info {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .contact-info h4 {
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.95rem;
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

            .details-card, .rejection-reason, .support-section {
                padding: 20px;
                margin: 20px 0;
            }

            .detail-label {
                min-width: 100px;
                font-size: 0.9rem;
            }

            .footer {
                padding: 25px 20px;
            }

            .contact-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Subtle animation */
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .container {
            animation: slideInUp 0.8s ease-out;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-icon">⚠️</div>
            <h1>Leave Request Decision</h1>
            <div class="header-subtitle">Application Status Update</div>
        </div>

        <div class="content">
            <div class="greeting">
                Hello <strong>{{ $employee->full_name }}</strong>,
            </div>

            <div class="main-message">
                <div class="main-message-content">
                    We have carefully reviewed your leave request, and unfortunately, we are unable to approve it at this time. We understand this may be disappointing, and we want to provide you with clear information about this decision.
                </div>
            </div>

            @if ($rejectionReason)
                <div class="rejection-reason">
                    <h4>📋 Reason for Decision</h4>
                    <div class="rejection-text">
                        {{ $rejectionReason }}
                    </div>
                </div>
            @endif

            <div class="details-card">
                <h3>Application Details</h3>
                <div class="details-grid">
                    <div class="detail-item">
                        <div class="detail-label">
                            <span>📝</span>
                            Leave Type:
                        </div>
                        <div class="detail-value">
                            <strong>{{ $leave->leave_type }}</strong>
                        </div>
                    </div>

                    @if (is_null($leave->leave_from) && is_null($leave->leave_to))
                        <div class="detail-item">
                            <div class="detail-label">
                                <span>📅</span>
                                Requested Date:
                            </div>
                            <div class="detail-value">
                                {{ $leave->leave_date }}
                            </div>
                        </div>
                    @else
                        <div class="detail-item">
                            <div class="detail-label">
                                <span>📅</span>
                                From Date:
                            </div>
                            <div class="detail-value">
                                {{ $leave->leave_from }}
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">
                                <span>📅</span>
                                To Date:
                            </div>
                            <div class="detail-value">
                                {{ $leave->leave_to }}
                            </div>
                        </div>
                    @endif

                    <div class="detail-item">
                        <div class="detail-label">
                            <span>💬</span>
                            Your Reason:
                        </div>
                        <div class="detail-value">
                            {{ $leave->reason }}
                        </div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">
                            <span>🏷️</span>
                            Final Status:
                        </div>
                        <div class="detail-value">
                            <span class="status-rejected">❌ Not Approved</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="support-section">
                <h4>💡 What Happens Next?</h4>
                <div class="support-content">
                    We encourage you to discuss this decision with your manager or HR representative to better understand the factors involved and explore potential alternatives.
                </div>
                <div class="next-steps">
                    <strong>Suggested Next Steps:</strong>
                    <ul>
                        <li>Schedule a meeting with your direct supervisor</li>
                        <li>Consider alternative dates for your leave request</li>
                        <li>Contact HR for clarification on company leave policies</li>
                        <li>Review workload and project timelines for better planning</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="footer">
            <p><strong>🤝 We're Here to Help</strong></p>
            <p>Our HR team is committed to supporting you through this process.</p>

            <div class="contact-info">
                <h4>📞 Contact Information</h4>
                <div class="contact-grid">
                    <div class="contact-item">
                        <span>📧</span>
                        <span>hr@company.com</span>
                    </div>
                    <div class="contact-item">
                        <span>📞</span>
                        <span>+1 (555) 123-4567</span>
                    </div>
                    <div class="contact-item">
                        <span>🕒</span>
                        <span>Mon-Fri, 9AM-5PM</span>
                    </div>
                    <div class="contact-item">
                        <span>🏢</span>
                        <span>HR Office - Room 205</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

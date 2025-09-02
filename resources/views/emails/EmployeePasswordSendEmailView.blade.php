<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Account Credentials - Welcome to the Team</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #2c3e50;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            position: relative;
        }

        .header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1) rotate(0deg);
                opacity: 0.5;
            }

            50% {
                transform: scale(1.1) rotate(180deg);
                opacity: 0.8;
            }
        }

        .welcome-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            display: block;
            position: relative;
            z-index: 1;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .header .subtitle {
            font-size: 16px;
            opacity: 0.9;
            font-weight: 300;
            position: relative;
            z-index: 1;
        }

        .content {
            padding: 40px 30px;
        }

        .greeting {
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 25px;
            text-align: center;
        }

        .welcome-message {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-left: 4px solid #4f46e5;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            font-size: 16px;
            color: #475569;
        }

        .credentials-section {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 25px;
            margin: 25px 0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .credentials-section::before {
            content: '🔐';
            position: absolute;
            top: -10px;
            right: -10px;
            font-size: 40px;
            opacity: 0.1;
            z-index: 0;
        }

        .credentials-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }

        .password-container {
            background: white;
            border: 2px dashed #4f46e5;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            position: relative;
            z-index: 1;
        }

        .password-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .password-value {
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 18px;
            font-weight: bold;
            color: #4f46e5;
            letter-spacing: 2px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .security-notice {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 4px solid #f59e0b;
            padding: 20px;
            border-radius: 8px;
            margin: 25px 0;
            font-size: 14px;
            color: #92400e;
        }

        .security-notice .icon {
            font-size: 18px;
            margin-right: 8px;
        }

        .next-steps {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }

        .next-steps h3 {
            color: #1e293b;
            font-size: 16px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .next-steps h3::before {
            content: '📋';
            margin-right: 10px;
        }

        .steps-list {
            list-style: none;
            padding: 0;
        }

        .steps-list li {
            padding: 8px 0;
            color: #475569;
            font-size: 14px;
            position: relative;
            padding-left: 25px;
        }

        .steps-list li::before {
            content: counter(step-counter);
            counter-increment: step-counter;
            position: absolute;
            left: 0;
            background: #4f46e5;
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
        }

        .steps-list {
            counter-reset: step-counter;
        }

        .footer {
            background: #f8fafc;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }

        .footer-message {
            font-size: 16px;
            color: #475569;
            margin-bottom: 15px;
        }

        .company-info {
            font-size: 12px;
            color: #64748b;
            line-height: 1.4;
        }

        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, #e2e8f0 50%, transparent 100%);
            margin: 25px 0;
        }

        @media (max-width: 600px) {
            body {
                padding: 10px;
            }

            .email-container {
                border-radius: 12px;
            }

            .header {
                padding: 30px 20px;
            }

            .content {
                padding: 30px 20px;
            }

            .header h1 {
                font-size: 24px;
            }

            .greeting {
                font-size: 18px;
            }
        }
    </style>
</head>

<body>
    <div class="email-container">
        <div class="header">
            <div class="welcome-icon">🎉</div>
            <h1>Welcome to the Team!</h1>
            <div class="subtitle">Your account has been created successfully</div>
        </div>

        <div class="content">
            <div class="greeting">
                Hello, {{ $data['name'] }}! 👋
            </div>

            <div class="welcome-message">
                We're excited to have you join our team! Your account has been set up and you can now access our systems
                with the credentials provided below.
            </div>

            <div class="credentials-section">
                <div class="credentials-title">Your Login Credentials</div>
                <div class="password-container">
                    <div class="password-label">Email Address</div>
                    <div class="password-value">{{ $data['email'] }}</div>
                </div>
                <div class="password-container">
                    <div class="password-label">Temporary Password</div>
                    <div class="password-value">{{ $data['password'] }}</div>
                </div>
            </div>

            <div class="security-notice">
                <span class="icon">⚠️</span>
                <strong>Important Security Notice:</strong> This is a temporary password. For your security, please
                change it immediately after your first login. Never share your credentials with anyone.
            </div>

            <div class="next-steps">
                <h3>Next Steps</h3>
                <ul class="steps-list">
                    <li>Visit our company portal and log in with your credentials</li>
                    <li>Change your temporary password to something secure</li>
                    <li>Complete your profile setup</li>
                    <li>Review the employee handbook and company policies</li>
                </ul>
            </div>

            <div class="divider"></div>

            <div class="footer-message">
                If you have any questions or need assistance, please don't hesitate to reach out to our IT support team.
            </div>
        </div>

        <div class="footer">
            <div class="company-info">
                This email contains sensitive information. Please handle with care.<br>
                © 2025 HRM SYSTEM. All rights reserved.
            </div>
        </div>
    </div>
</body>

</html>

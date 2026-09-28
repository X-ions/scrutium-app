<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>Reset your Scrutium password</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            background-color: #f3f4f6;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        @media (prefers-color-scheme: dark) {
            body {
                color: #f3f4f6;
                background-color: #111827;
            }
        }
        .wrapper {
            min-height: 100vh;
            padding: 40px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            width: 100%;
            max-width: 480px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        @media (prefers-color-scheme: dark) {
            .container {
                background: #1f2937;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -2px rgba(0, 0, 0, 0.2);
            }
        }
        .header {
            background: linear-gradient(135deg, #0b1b33 0%, #163056 100%);
            padding: 32px 24px;
            text-align: center;
        }
        .logo {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: #ffffff;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logo img {
            width: 32px;
            height: 32px;
            object-fit: cover;
        }
        .header h1 {
            color: #ffffff;
            font-size: 24px;
            font-weight: 600;
            margin: 0;
        }
        .content {
            padding: 32px 24px;
        }
        .greeting {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #111827;
        }
        @media (prefers-color-scheme: dark) {
            .greeting {
                color: #f9fafb;
            }
        }
        .message {
            color: #4b5563;
            font-size: 15px;
            margin-bottom: 24px;
            line-height: 1.7;
        }
        @media (prefers-color-scheme: dark) {
            .message {
                color: #d1d5db;
            }
        }
        .cta-button {
            display: inline-block;
            background: #0b1b33;
            color: #ffffff !important;
            text-decoration: none;
            padding: 14px 28px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 15px;
            text-align: center;
            transition: background-color 0.2s ease;
            border: none;
            cursor: pointer;
        }
        .cta-button:hover {
            background: #000000;
        }
        .cta-container {
            text-align: center;
            margin: 32px 0;
        }
        .divider {
            border-top: 1px solid #e5e7eb;
            margin: 24px 0;
        }
        @media (prefers-color-scheme: dark) {
            .divider {
                border-color: #374151;
            }
        }
        .footer-text {
            font-size: 13px;
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 12px;
        }
        @media (prefers-color-scheme: dark) {
            .footer-text {
                color: #9ca3af;
            }
        }
        .link-fallback {
            word-break: break-all;
            font-size: 13px;
            color: #0b1b33;
            background: #f3f4f6;
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 16px;
        }
        @media (prefers-color-scheme: dark) {
            .link-fallback {
                color: #93c5fd;
                background: #1f2937;
            }
        }
        .link-fallback a {
            color: inherit;
            text-decoration: underline;
        }
        .footer {
            border-top: 1px solid #e5e7eb;
            padding: 24px;
            text-align: center;
            background: #f9fafb;
        }
        @media (prefers-color-scheme: dark) {
            .footer {
                border-color: #374151;
                background: #111827;
            }
        }
        .footer-brand {
            font-weight: 600;
            font-size: 14px;
            color: #111827;
            margin-bottom: 8px;
        }
        @media (prefers-color-scheme: dark) {
            .footer-brand {
                color: #f9fafb;
            }
        }
        .footer-links {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin-bottom: 16px;
        }
        .footer-links a {
            color: #6b7280;
            text-decoration: none;
            font-size: 13px;
        }
        @media (prefers-color-scheme: dark) {
            .footer-links a {
                color: #9ca3af;
            }
        }
        .copyright {
            font-size: 12px;
            color: #9ca3af;
        }
        @media (prefers-color-scheme: dark) {
            .copyright {
                color: #6b7280;
            }
        }
        .warning-box {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }
        @media (prefers-color-scheme: dark) {
            .warning-box {
                background: #451a03;
                border-color: #f59e0b;
            }
        }
        .warning-box .warning-title {
            font-weight: 600;
            color: #92400e;
            margin-bottom: 4px;
            font-size: 14px;
        }
        @media (prefers-color-scheme: dark) {
            .warning-box .warning-title {
                color: #fbbf24;
            }
        }
        .warning-box .warning-text {
            font-size: 13px;
            color: #b45309;
        }
        @media (prefers-color-scheme: dark) {
            .warning-box .warning-text {
                color: #fcd34d;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <div class="logo">
                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect width="32" height="32" rx="8" fill="#0b1b33"/>
                        <path d="M8 12h16M8 16h12M8 20h8" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <h1>Scrutium</h1>
            </div>

            <div class="content">
                <p class="greeting">Reset your password</p>

                <p class="message">We received a request to reset the password for your Scrutium workspace. Click the button below to choose a new password.</p>

                <div class="cta-container">
                    <a href="{{ $url }}" class="cta-button">Choose a new password</a>
                </div>

                <div class="divider"></div>

                <div class="warning-box">
                    <div class="warning-title">Security notice</div>
                    <div class="warning-text">This link expires in <strong>60 minutes</strong> and can be used only once. If you didn't request this, you can safely ignore this email.</div>
                </div>

                <p class="message">If the button above doesn't work, copy and paste this link into your browser:</p>
                <div class="link-fallback"><a href="{{ $url }}">{{ $url }}</a></div>
            </div>

            <div class="footer">
                <div class="footer-brand">Scrutium</div>
                <div class="footer-links">
                    <a href="{{ config('app.url') }}">Dashboard</a>
                    <a href="{{ config('app.url') }}/signin">Sign in</a>
                    <a href="#">Support</a>
                </div>
                <div class="copyright">&copy; {{ date('Y') }}/2026 X-ion, Inc. All Rights Reserved.</div>
            </div>
        </div>
    </div>
</body>
</html>
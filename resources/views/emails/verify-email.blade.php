<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Verify your email address</title>
    <style>
        @media (prefers-color-scheme: dark) {
            .dark-mode { display: block !important; }
            .light-mode { display: none !important; }
            .bg-auto { background-color: #0f172a !important; }
            .text-auto { color: #f1f5f9 !important; }
            .border-auto { border-color: #1e293b !important; }
            .bg-card { background-color: #1e293b !important; }
            .text-muted { color: #94a3b8 !important; }
            .btn-primary { background-color: #3b82f6 !important; border-color: #3b82f6 !important; }
            .btn-primary:hover { background-color: #2563eb !important; }
        }
        @media (prefers-color-scheme: light) {
            .dark-mode { display: none !important; }
            .light-mode { display: block !important; }
        }
        .dark-mode { display: none; }
        .light-mode { display: block; }
    </style>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; line-height: 1.6; background-color: #f8fafc;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="min-width: 320px;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #ffffff; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; overflow: hidden;" class="bg-auto">
                    <tr>
                        <td style="padding: 40px 48px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" style="padding-bottom: 32px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td>
                                                    <a href="{{ config('app.url') }}" style="text-decoration: none; display: inline-flex; align-items: center; gap: 10px;">
                                                        <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: block;">
                                                            <rect width="36" height="36" rx="10" fill="#3b82f6"/>
                                                            <path d="M12 18L16.5 22.5L24 15" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                                        </svg>
                                                        <span style="font-size: 22px; font-weight: 700; color: #0f172a; letter-spacing: -0.02em;" class="text-auto">Scrutium</span>
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="border-top: 1px solid #e2e8f0; padding-top: 32px;" class="border-auto">
                                        <h1 style="margin: 0 0 16px; font-size: 24px; font-weight: 700; color: #0f172a; letter-spacing: -0.02em;" class="text-auto">Verify your email address</h1>
                                        <p style="margin: 0 0 24px; font-size: 16px; color: #475569;" class="text-muted">Hi {{ $first_name }},</p>
                                        <p style="margin: 0 0 24px; font-size: 16px; color: #475569;" class="text-muted">Thanks for signing up for Scrutium. Please confirm your email address to finish setting up your account.</p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 32px;">
                                            <tr>
                                                <td align="center">
                                                    <a href="{{ $url }}" style="display: inline-block; padding: 14px 32px; background-color: #3b82f6; color: #ffffff; font-size: 16px; font-weight: 600; text-decoration: none; border-radius: 10px; box-shadow: 0 4px 14px 0 rgba(59, 130, 246, 0.4);" class="btn-primary">
                                                        Verify Email Address
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style="margin: 0 0 8px; font-size: 14px; color: #64748b;" class="text-muted">If the button does not work, copy and paste this link into your web browser:</p>
                                        <p style="margin: 0 0 24px; font-size: 13px; color: #3b82f6; word-break: break-all; font-family: monospace;" class="text-muted">{{ $url }}</p>
                                        <hr style="margin: 24px 0; border: none; border-top: 1px solid #e2e8f0;" class="border-auto">
                                        <p style="margin: 0 0 8px; font-size: 13px; color: #64748b;" class="text-muted"><strong>Security note:</strong> This link expires in 24 hours.</p>
                                        <p style="margin: 0; font-size: 13px; color: #64748b;" class="text-muted">If you did not create a Scrutium account, you can safely ignore this email.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="border-top: 1px solid #e2e8f0; padding-top: 24px;" class="border-auto">
                                        <p style="margin: 0; font-size: 12px; color: #94a3b8; text-align: center;" class="text-muted">&copy; {{ date('Y') }}/2026 X-ion, Inc. All Rights Reserved.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
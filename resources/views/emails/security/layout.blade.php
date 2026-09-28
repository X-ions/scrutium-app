<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>@yield('title', 'Security alert')</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
        @yield('preheader', 'Security notification for your Scrutium account.')
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.1);">
                    <tr>
                        <td style="padding:28px 40px;border-bottom:1px solid #e2e8f0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td>
                                        <span style="font-size:18px;font-weight:700;color:#0f172a;letter-spacing:-.02em;">Scrutium</span>
                                        <span style="font-size:12px;color:#94a3b8;margin-left:8px;">Security</span>
                                    </td>
                                    <td align="right" style="font-size:12px;color:#94a3b8;">
                                        @yield('badge')
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 40px;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 40px;background:#f8fafc;border-top:1px solid #e2e8f0;">
                            @yield('actions')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 40px;border-top:1px solid #e2e8f0;">
                            <p style="margin:0 0 6px;font-size:12px;line-height:1.6;color:#94a3b8;text-align:center;">
                                X-ION, Inc. &bull; Tom Mboya Street &bull; Nairobi, Kenya &bull; 1&deg; 17' 31" S, 36&deg; 49' 19" E
                            </p>
                            <p style="margin:0;font-size:12px;color:#94a3b8;text-align:center;">
                                &copy; {{ date('Y') }} X-ION, Inc. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

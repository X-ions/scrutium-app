<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Welcome to Scrutium</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f8fafc; color: #0f172a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="min-width: 320px;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px;">
                    <tr>
                        <td style="padding: 40px;">
                            <p style="margin: 0 0 24px; color: #2563eb; font-size: 14px; font-weight: 700;">SCRUTIUM</p>
                            <h1 style="margin: 0 0 16px; font-size: 24px; line-height: 1.3;">Welcome to Scrutium</h1>
                            <p style="margin: 0 0 16px;">Hello {{ $user_name }},</p>
                            <p style="margin: 0 0 16px;">Welcome to <strong>{{ $workspace_name }}</strong>.</p>
                            <p style="margin: 0 0 24px;">Your account has been successfully created and is now ready to use.</p>

                            <h2 style="margin: 0 0 12px; font-size: 16px;">Account Details</h2>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 24px; border-collapse: collapse;">
                                <tr>
                                    <th align="left" style="padding: 10px 12px; border: 1px solid #e2e8f0; background-color: #f8fafc; font-size: 14px;">Workspace</th>
                                    <td style="padding: 10px 12px; border: 1px solid #e2e8f0; font-size: 14px;">{{ $workspace_name }}</td>
                                </tr>
                                <tr>
                                    <th align="left" style="padding: 10px 12px; border: 1px solid #e2e8f0; background-color: #f8fafc; font-size: 14px;">Account Name</th>
                                    <td style="padding: 10px 12px; border: 1px solid #e2e8f0; font-size: 14px;">{{ $user_name }}</td>
                                </tr>
                                <tr>
                                    <th align="left" style="padding: 10px 12px; border: 1px solid #e2e8f0; background-color: #f8fafc; font-size: 14px;">User ID</th>
                                    <td style="padding: 10px 12px; border: 1px solid #e2e8f0; font-size: 14px;">{{ $user_id }}</td>
                                </tr>
                            </table>

                            <p style="margin: 0 0 16px;">You can now access your workspace and begin managing campaigns, monitoring performance, collaborating with your team, and exploring the platform's capabilities.</p>
                            <p style="margin: 0 0 16px;">If you encounter any issues or have questions, our support team is available to assist you.</p>
                            <p style="margin: 0 0 24px;">Thank you for choosing Scrutium.</p>
                            <p style="margin: 0 0 24px;">Best regards,<br>The X-ion, Inc. Team.</p>

                            <hr style="margin: 0 0 16px; border: 0; border-top: 1px solid #e2e8f0;">
                            <p style="margin: 0; color: #64748b; font-size: 12px;">This is an automated message. Please do not reply directly to this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
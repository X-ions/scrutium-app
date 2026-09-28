@extends('emails.security.layout')

@section('title', 'New sign-in detected')
@section('preheader', 'A device we do not recognise signed in to your account. Confirm it was you.')
@section('badge')
    <span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-weight:600;">{{ ucfirst($risk_score) }} risk</span>
@endsection

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:700;color:#0f172a;letter-spacing:-.02em;">
        {{ $headline }}
    </h1>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#475569;">
        {{ $summary }}
    </p>

    @if (! empty($signals))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
            <tr>
                <td style="padding:16px 18px;">
                    @foreach ($signals as $signal)
                        <p style="margin:0 0 6px;font-size:14px;line-height:1.5;color:#334155;">
                            <span style="color:#2563eb;">&#8226;</span> {{ $signal }}
                        </p>
                    @endforeach
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;border:1px solid #e2e8f0;border-radius:12px;">
        <tr><td colspan="2" style="padding:14px 18px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:13px;font-weight:700;color:#0f172a;">Sign-in details</td></tr>
        @foreach ($rows as $label => $value)
            <tr>
                <td width="40%" style="padding:10px 18px;font-size:14px;color:#64748b;border-bottom:1px solid #f1f5f9;vertical-align:top;">{{ $label }}</td>
                <td style="padding:10px 18px;font-size:14px;color:#0f172a;font-weight:500;border-bottom:1px solid #f1f5f9;word-break:break-word;">{{ $value }}</td>
            </tr>
        @endforeach
    </table>

    <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
        This email was sent to {{ $user->email }} because a sign-in came from a device you have not confirmed before.
        Quote event ID <strong style="color:#475569;">#{{ $event->id }}</strong> if you contact support.
    </p>
@endsection

@section('actions')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            @if ($confirm_url)
                <td align="center" style="padding:6px;">
                    <a href="{{ $confirm_url }}" style="display:inline-block;padding:13px 26px;background:#16a34a;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;border-radius:8px;">
                        This was me
                    </a>
                </td>
            @endif
            <td align="center" style="padding:6px;">
                <a href="{{ $security_url }}" style="display:inline-block;padding:13px 26px;background:#ffffff;color:#b91c1c;font-size:15px;font-weight:600;text-decoration:none;border:1px solid #fecaca;border-radius:8px;">
                    Secure my account
                </a>
            </td>
        </tr>
        @if ($confirm_url)
            <tr>
                <td colspan="2" style="padding:14px 0 0;font-size:12px;line-height:1.5;color:#94a3b8;">
                    Did not recognise it? Do not confirm the device. Review your
                    <a href="{{ $sessions_url }}" style="color:#2563eb;">active sessions</a>,
                    sign out unknown devices, and change your password.
                </td>
            </tr>
        @endif
    </table>
@endsection

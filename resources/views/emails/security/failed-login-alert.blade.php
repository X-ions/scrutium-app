@extends('emails.security.layout')

@section('title', 'Failed sign-in attempt')
@section('preheader', 'Someone tried to sign in to your account with an incorrect password.')
@section('badge')
    <span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#fff7ed;color:#c2410c;font-weight:600;">Failed sign-in</span>
@endsection

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:700;color:#0f172a;letter-spacing:-.02em;">
        {{ $headline }}
    </h1>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#475569;">
        {{ $summary }}
    </p>

    @if (! empty($signals))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;">
            <tr>
                <td style="padding:16px 18px;">
                    @foreach ($signals as $signal)
                        <p style="margin:0 0 6px;font-size:14px;line-height:1.5;color:#9a3412;">
                            <span style="color:#ea580c;">&#8226;</span> {{ $signal }}
                        </p>
                    @endforeach
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;border:1px solid #e2e8f0;border-radius:12px;">
        <tr><td colspan="2" style="padding:14px 18px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:13px;font-weight:700;color:#0f172a;">Attempt details</td></tr>
        @foreach ($rows as $label => $value)
            <tr>
                <td width="40%" style="padding:10px 18px;font-size:14px;color:#64748b;border-bottom:1px solid #f1f5f9;vertical-align:top;">{{ $label }}</td>
                <td style="padding:10px 18px;font-size:14px;color:#0f172a;font-weight:500;border-bottom:1px solid #f1f5f9;word-break:break-word;">{{ $value }}</td>
            </tr>
        @endforeach
    </table>

    <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
        If this was you and you mistyped your password, no action is needed. If it was not you,
        we recommend changing your password. Quote event ID <strong style="color:#475569;">#{{ $event->id }}</strong> if you contact support.
    </p>
@endsection

@section('actions')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center" style="padding:6px;">
                <a href="{{ $security_url }}" style="display:inline-block;padding:13px 26px;background:#ffffff;color:#b91c1c;font-size:15px;font-weight:600;text-decoration:none;border:1px solid #fecaca;border-radius:8px;">
                    Review account security
                </a>
            </td>
        </tr>
    </table>
@endsection

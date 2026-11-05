<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subject }}</title>
</head>
{{-- Email de prospection (M8) : texte simple, un seul bouton, aucune image ni pixel de suivi. --}}
<body style="margin:0;padding:0;background:#ffffff;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;">
        <tr>
            <td align="left" style="padding:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
                    <tr>
                        <td style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#1f2937;">
                            {!! $bodyHtml !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 0 24px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-radius:6px;background:#1d4ed8;">
                                        <a href="{{ $ctaUrl }}" style="display:inline-block;padding:11px 20px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;">{{ $ctaLabel }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="border-top:1px solid #e5e7eb;padding-top:12px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#6b7280;">
                            @foreach ($footer as $line)
                                <p style="margin:0 0 6px;">{!! $line['html'] !!}</p>
                            @endforeach
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

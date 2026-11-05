<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Désinscription confirmée</title>
</head>
<body style="margin:0;padding:32px 16px;font-family:Arial,Helvetica,sans-serif;color:#1f2937;background:#f9fafb;">
    <main style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;">
        <h1 style="font-size:20px;margin:0 0 12px;">Désinscription confirmée</h1>
        <p style="margin:0 0 8px;">L’adresse <strong>{{ $email }}</strong> ne recevra plus aucun email de {{ config('prospection.sender.company') }}.</p>
        <p style="margin:0;color:#6b7280;font-size:14px;">Aucune autre démarche n’est nécessaire.</p>
    </main>
</body>
</html>

@php
    // Set by the preview command (--inline-logo) to embed the emblem; mail uses the panel's stable URLs.
    $logo = $ramjetEmailLogo ?? [];
    $logoSrc = $logo['src'] ?? url('/extensions/ramjet-theme/email/emblem.png');
    $logoSrc2x = $logo['src2x'] ?? url('/extensions/ramjet-theme/email/emblem@2x.png');

    $isError = ($level ?? 'info') === 'error';
    // Destructive red for error-level mails, afterburner orange otherwise. Dark ink text on both.
    $buttonColour = $isError ? '#ff6b6b' : '#ff7a1a';
    $headline = filled($greeting ?? null) ? $greeting : ($isError ? 'Whoops!' : 'Hello!');
    // Hidden preheader: the inbox preview line, taken from the first intro line.
    $preheader = ($introLines[0] ?? null) ?? $headline;
    $signOff = filled($salutation ?? null) ? $salutation : 'Regards,';
    $linkText = $displayableActionUrl ?? $actionUrl ?? '';

    $font = "Archivo, 'Helvetica Neue', Helvetica, Arial, sans-serif";
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>Ramjet</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style type="text/css">
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }

        @media only screen and (max-width: 620px) {
            .rj-container { width: 100% !important; }
            .rj-pad { padding-left: 24px !important; padding-right: 24px !important; }
            .rj-header-pad { padding-left: 24px !important; padding-right: 24px !important; }
        }

        /* Dark mode: keep the brand palette (near-black surfaces, light text). !important beats the inline styles. */
        @media (prefers-color-scheme: dark) {
            .rj-outer { background-color: #0a0b0d !important; }
            .rj-card { background-color: #111317 !important; }
            .rj-heading { color: #eceef1 !important; }
            .rj-text { color: #d5d9df !important; }
            .rj-muted { color: #9aa1ac !important; }
            .rj-link { color: #ff9a52 !important; }
            .rj-rule { border-top-color: #262a31 !important; }
            .rj-fallback { background-color: #15181c !important; }
        }
    </style>
</head>
<body class="rj-outer" style="margin:0; padding:0; width:100%; background-color:#eef0f3;">
    <!-- Preheader: shown as the inbox preview line, hidden in the message itself. -->
    <div style="display:none; font-size:1px; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all;">
        {{ $preheader }}
        &#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="rj-outer" style="background-color:#eef0f3;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <!--[if mso]>
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td>
                <![endif]-->
                <table role="presentation" class="rj-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">

                    <!-- Header: dark brand band with the emblem and wordmark -->
                    <tr>
                        <td bgcolor="#0a0b0d" class="rj-header-pad" style="background-color:#0a0b0d; border-radius:12px 12px 0 0; padding:22px 32px; border-bottom:3px solid #ff7a1a;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="40" valign="middle" style="width:40px;">
                                        <a href="https://ramjet.gg" target="_blank" style="text-decoration:none;">
                                            <img src="{{ $logoSrc }}" srcset="{{ $logoSrc }} 1x, {{ $logoSrc2x }} 2x" width="40" height="40" alt="Ramjet" style="display:block; width:40px; height:40px; border:0;">
                                        </a>
                                    </td>
                                    <td valign="middle" style="padding-left:12px; font-family:{{ $font }}; font-size:20px; line-height:40px; font-weight:700; letter-spacing:3px; color:#eceef1; text-transform:uppercase;">
                                        <a href="https://ramjet.gg" target="_blank" style="color:#eceef1; text-decoration:none;">Ramjet</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content card -->
                    <tr>
                        <td bgcolor="#ffffff" class="rj-card rj-pad" style="background-color:#ffffff; padding:40px 40px 36px 40px; font-family:{{ $font }};">

                            <h1 class="rj-heading" style="margin:0 0 20px 0; font-family:{{ $font }}; font-size:24px; line-height:1.3; font-weight:700; color:#0a0b0d;">{{ $headline }}</h1>

                            @foreach (($introLines ?? []) as $line)
                                <p class="rj-text" style="margin:0 0 16px 0; font-family:{{ $font }}; font-size:16px; line-height:1.6; color:#2b2f36;">{{ $line }}</p>
                            @endforeach

                            @isset($actionText)
                                <!-- Call to action: bulletproof button, with a VML version for Outlook -->
                                <table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:28px auto;">
                                    <tr>
                                        <td align="center" bgcolor="{{ $buttonColour }}" style="border-radius:8px; background-color:{{ $buttonColour }};">
                                            <!--[if mso]>
                                            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $actionUrl }}" style="height:48px; v-text-anchor:middle; width:260px;" arcsize="17%" stroke="f" fillcolor="{{ $buttonColour }}">
                                                <w:anchorlock/>
                                                <center style="color:#0a0b0d; font-family:Arial, Helvetica, sans-serif; font-size:16px; font-weight:bold;">{{ $actionText }}</center>
                                            </v:roundrect>
                                            <![endif]-->
                                            <!--[if !mso]><!-->
                                            <a href="{{ $actionUrl }}" target="_blank" style="display:inline-block; padding:15px 32px; border-radius:8px; background-color:{{ $buttonColour }}; border:1px solid {{ $buttonColour }}; font-family:{{ $font }}; font-size:16px; font-weight:700; line-height:16px; color:#0a0b0d; text-decoration:none;">{{ $actionText }}</a>
                                            <!--<![endif]-->
                                        </td>
                                    </tr>
                                </table>
                            @endisset

                            @foreach (($outroLines ?? []) as $line)
                                <p class="rj-text" style="margin:0 0 16px 0; font-family:{{ $font }}; font-size:16px; line-height:1.6; color:#2b2f36;">{{ $line }}</p>
                            @endforeach

                            <p class="rj-text" style="margin:24px 0 0 0; font-family:{{ $font }}; font-size:16px; line-height:1.6; color:#2b2f36;">
                                {{ $signOff }}<br>
                                <span class="rj-heading" style="font-weight:700; color:#0a0b0d;">Ramjet</span>
                            </p>

                            @isset($actionText)
                                <!-- Fallback for clients that strip the button -->
                                <table role="presentation" class="rj-rule" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:32px; border-top:1px solid #e4e7ec;">
                                    <tr>
                                        <td class="rj-muted rj-fallback" style="padding-top:20px; font-family:{{ $font }}; font-size:13px; line-height:1.6; color:#5b6370;">
                                            If you’re having trouble clicking the &ldquo;{{ $actionText }}&rdquo; button, copy and paste the URL below into your web browser:
                                            <br>
                                            <a class="rj-link" href="{{ $actionUrl }}" target="_blank" style="color:#c2410c; text-decoration:underline; word-break:break-all;">{{ $linkText }}</a>
                                        </td>
                                    </tr>
                                </table>
                            @endisset
                        </td>
                    </tr>

                    <!-- Footer: dark brand band -->
                    <tr>
                        <td bgcolor="#0a0b0d" class="rj-header-pad" style="background-color:#0a0b0d; border-radius:0 0 12px 12px; padding:28px 32px; text-align:center; font-family:{{ $font }}; font-size:13px; line-height:1.6; color:#9aa1ac;">
                            <a href="https://ramjet.gg" target="_blank" style="color:#ff9a52; font-weight:700; text-decoration:none;">Ramjet &middot; ramjet.gg</a>
                            <p style="margin:12px 0 0 0; font-family:{{ $font }}; font-size:13px; line-height:1.6; text-align:center; color:#9aa1ac;">You received this email because you have an account on Ramjet.</p>
                            <p style="margin:6px 0 0 0; font-family:{{ $font }}; font-size:12px; line-height:1.5; text-align:center; color:#7b8390;">&copy; {{ date('Y') }} Ramjet. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
                <!--[if mso]>
                </td></tr></table>
                <![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>

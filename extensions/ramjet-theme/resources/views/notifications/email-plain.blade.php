@php
    // The text part of a notification (Ramjet\Theme\Email\TextPartMarkdown). Same content as email.blade.php, without markup.
    $isError = ($level ?? 'info') === 'error';
    $headline = filled($greeting ?? null) ? $greeting : ($isError ? 'Whoops!' : 'Hello!');
    $signOff = filled($salutation ?? null) ? $salutation : 'Regards,';
@endphp
{{ $headline }}

@foreach (($introLines ?? []) as $line)
{{ $line }}

@endforeach
@isset($actionText)
{{ $actionText }}: {{ $displayableActionUrl ?? $actionUrl }}

@endisset
@foreach (($outroLines ?? []) as $line)
{{ $line }}

@endforeach
{{ $signOff }}
Ramjet

--
Ramjet · https://ramjet.gg
You received this email because you have an account on Ramjet.

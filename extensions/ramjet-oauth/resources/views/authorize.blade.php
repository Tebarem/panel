<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <meta name="theme-color" content="#0e4688">
        <title>Authorize {{ $client->name }} | {{ config('app.name', 'Pterodactyl') }}</title>
        <style>
            :root {
                --bg: #f5f5f5;
                --card: #ffffff;
                --text: #171717;
                --muted: #6b6b6b;
                --border: #e5e5e5;
                --primary: #0e4688;
                --primary-text: #ffffff;
                --secondary-bg: #f5f5f5;
                --secondary-text: #171717;
            }

            @media (prefers-color-scheme: dark) {
                :root:not([data-theme="light"]) {
                    --bg: #171717;
                    --card: #262626;
                    --text: #e5e5e5;
                    --muted: #a3a3a3;
                    --border: #404040;
                    --primary: #2f6fb8;
                    --primary-text: #ffffff;
                    --secondary-bg: #333333;
                    --secondary-text: #e5e5e5;
                }
            }

            :root[data-theme="dark"] {
                --bg: #171717;
                --card: #262626;
                --text: #e5e5e5;
                --muted: #a3a3a3;
                --border: #404040;
                --primary: #2f6fb8;
                --primary-text: #ffffff;
                --secondary-bg: #333333;
                --secondary-text: #e5e5e5;
            }

            * { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100vh;
                background: var(--bg);
                color: var(--text);
                font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
                font-size: 15px;
                line-height: 1.5;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 16px;
            }

            .card {
                width: 100%;
                max-width: 440px;
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: 8px;
                padding: 28px 24px;
            }

            .brand { margin: 0 0 4px; font-size: 13px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; }
            h1 { margin: 0 0 4px; font-size: 22px; word-break: break-word; }
            .lead { margin: 0 0 20px; color: var(--muted); }
            .identity { margin: 0 0 20px; padding: 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px; word-break: break-all; }

            h2 { font-size: 14px; margin: 0 0 8px; }
            ul.scopes { list-style: none; padding: 0; margin: 0 0 24px; }
            ul.scopes li { padding: 10px 0; border-top: 1px solid var(--border); }
            ul.scopes li:last-child { border-bottom: 1px solid var(--border); }
            ul.scopes code { display: block; font-size: 12px; color: var(--muted); margin-top: 2px; }
            .none { color: var(--muted); margin: 0 0 24px; }

            .actions { display: flex; gap: 12px; }
            .actions form { flex: 1; margin: 0; }
            button {
                width: 100%;
                padding: 10px 14px;
                border: 0;
                border-radius: 6px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
            }
            .approve { background: var(--primary); color: var(--primary-text); }
            .deny { background: var(--secondary-bg); color: var(--secondary-text); }
            button:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

            .fine { margin: 20px 0 0; font-size: 12px; color: var(--muted); }

            @media (max-width: 480px) {
                .card { padding: 20px 16px; }
            }
        </style>
    </head>
    <body>
        <main class="card">
            <p class="brand">{{ config('app.name', 'Pterodactyl') }}</p>
            <h1>{{ $client->name }}</h1>
            <p class="lead">wants to access your account.</p>

            <div class="identity">
                Signed in as <strong>{{ $user->username }}</strong>
                @if (!empty($user->email))
                    <span style="color: var(--muted);">({{ $user->email }})</span>
                @endif
            </div>

            <h2>This will allow the application to:</h2>

            @if (count($scopes) > 0)
                <ul class="scopes">
                    @foreach ($scopes as $scope)
                        <li>
                            {{ $scope->description }}
                            <code>{{ $scope->id }}</code>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="none">No additional permissions are requested.</p>
            @endif

            <div class="actions">
                <form method="post" action="{{ route('extensions.ramjet-oauth.root.oauth.authorize.approve') }}">
                    @csrf
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    @isset($authToken)
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    @endisset
                    <button type="submit" class="approve">Authorize</button>
                </form>

                <form method="post" action="{{ route('extensions.ramjet-oauth.root.oauth.authorize.deny') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    @isset($authToken)
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    @endisset
                    <button type="submit" class="deny">Cancel</button>
                </form>
            </div>

            <p class="fine">Only authorize applications you trust. Authorizing shares the information listed above with this application.</p>
        </main>
    </body>
</html>

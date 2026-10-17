<?php

declare(strict_types=1);

namespace Ramjet\Theme\Email;

use Illuminate\Notifications\Notification;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Notifications\AccountCreated;
use Pterodactyl\Notifications\AddedToServer;
use Pterodactyl\Notifications\MailTested;
use Pterodactyl\Notifications\RemovedFromServer;
use Pterodactyl\Notifications\SendPasswordReset;
use Pterodactyl\Notifications\ServerInstalled;

/**
 * Every notification the panel mails, built from in-memory models so nothing is read from or written to the database.
 * The preview command renders them and the tests render them through the theme; both get the same messages.
 */
final class PreviewNotifications
{
    /**
     * @return list<array{slug: string, title: string, notifiable: User, notification: Notification}>
     */
    public static function samples(string $userName = 'Ada Lovelace', string $serverName = 'Survival Realm'): array
    {
        // `name` is derived from name_first and name_last on the model.
        [$first, $last] = array_pad(explode(' ', $userName, 2), 2, '');

        $user = new User;
        $user->name_first = $first;
        $user->name_last = $last;
        $user->username = 'ada';
        $user->email = 'ada@example.com';

        $server = new Server;
        $server->name = $serverName;
        $server->uuidShort = 'a1b2c3d4';

        $installed = new ServerInstalled;
        $installed->server = $server;
        $installed->user = $user;

        return [
            [
                'slug' => 'account-created',
                'title' => 'Account created',
                'notifiable' => $user,
                'notification' => new AccountCreated($user, 'preview-token'),
            ],
            [
                'slug' => 'password-reset',
                'title' => 'Password reset',
                'notifiable' => $user,
                'notification' => new SendPasswordReset('preview-token'),
            ],
            [
                'slug' => 'server-installed',
                'title' => 'Server installed',
                'notifiable' => $user,
                'notification' => $installed,
            ],
            [
                'slug' => 'added-to-server',
                'title' => 'Added to server',
                'notifiable' => $user,
                'notification' => new AddedToServer(['user' => $userName, 'name' => $serverName, 'uuidShort' => 'a1b2c3d4']),
            ],
            [
                'slug' => 'removed-from-server',
                'title' => 'Removed from server',
                'notifiable' => $user,
                'notification' => new RemovedFromServer(['user' => $userName, 'name' => $serverName]),
            ],
            [
                'slug' => 'mail-test',
                'title' => 'Mail test',
                'notifiable' => $user,
                'notification' => new MailTested($user),
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Ramjet\ProxyProvisioning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * The Ramjet Proxy a customer created for themselves. The user id is the primary key: at most one row per user.
 */
final class UserProxy extends Model
{
    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'ramjet_user_proxies';

    protected $primaryKey = 'user_id';

    protected $fillable = ['user_id', 'server_id', 'created_at'];

    protected $casts = [
        'user_id' => 'integer',
        'server_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

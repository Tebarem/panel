<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Registries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Egg;

/**
 * The Docker registry an egg pulls its images with. An egg without a row (or with a null
 * registry) pulls anonymously.
 *
 * @property int $egg_id
 * @property int|null $registry_id
 * @property-read DockerRegistry|null $registry
 */
final class EggRegistryAssignment extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'ramjet_egg_registries';

    protected $primaryKey = 'egg_id';

    protected $fillable = [
        'egg_id',
        'registry_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'egg_id' => 'integer',
        'registry_id' => 'integer',
    ];

    /**
     * @return BelongsTo<DockerRegistry, $this>
     */
    public function registry(): BelongsTo
    {
        return $this->belongsTo(DockerRegistry::class, 'registry_id');
    }

    /**
     * @return BelongsTo<Egg, $this>
     */
    public function egg(): BelongsTo
    {
        return $this->belongsTo(Egg::class, 'egg_id');
    }
}

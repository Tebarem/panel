<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Egg;

/**
 * The link between an egg and the source file it was imported from.
 *
 * @property int $egg_id
 * @property int $source_id
 * @property string|null $revision
 * @property string $path
 */
final class EggSourceEgg extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'ramjet_egg_source_eggs';

    protected $primaryKey = 'egg_id';

    /** @var list<string> */
    protected $fillable = ['egg_id', 'source_id', 'path', 'revision'];

    /** @var array<string, string> */
    protected $casts = [
        'egg_id' => 'integer',
        'source_id' => 'integer',
    ];

    /**
     * @return BelongsTo<Egg, $this>
     */
    public function egg(): BelongsTo
    {
        return $this->belongsTo(Egg::class, 'egg_id');
    }

    /**
     * @return BelongsTo<EggSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(EggSource::class, 'source_id');
    }
}

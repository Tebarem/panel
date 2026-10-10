<?php

namespace Pterodactyl\Services\Eggs;

use Pterodactyl\Models\Egg;
use Pterodactyl\Contracts\Repository\EggRepositoryInterface;
use Pterodactyl\Exceptions\Service\Egg\NoParentConfigurationFoundException;

class EggUpdateService
{
    /**
     * EggUpdateService constructor.
     */
    public function __construct(protected EggRepositoryInterface $repository)
    {
    }

    /**
     * Update a service option.
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     * @throws NoParentConfigurationFoundException
     */
    public function handle(Egg $egg, array $data): void
    {
        if (!is_null(array_get($data, 'config_from'))) {
            $results = $this->repository->findCountWhere([
                ['nest_id', '=', $egg->nest_id],
                ['id', '=', array_get($data, 'config_from')],
            ]);

            if ($results !== 1) {
                throw new NoParentConfigurationFoundException(trans('exceptions.nest.egg.must_be_child'));
            }
        }

        // The file rules ("file_denylist" and "file_hidden") are only ever present in $data when
        // they were submitted by the admin form, so they can be updated here without any extra
        // handling. Callers are responsible for pushing the change to Wings (see EggServerSyncService).
        $this->repository->withoutFreshModel()->update($egg->id, $data);
    }
}

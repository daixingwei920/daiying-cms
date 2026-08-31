<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class MarketJobMigrator
{
    public function __construct(
        private readonly MarketJobRepository $jsonRepository,
        private readonly DatabaseMarketJobRepository $databaseRepository,
    ) {
    }

    public function migrateJsonToDatabase(): int
    {
        $count = 0;
        foreach ($this->jsonRepository->all() as $job) {
            $id = (string) ($job['id'] ?? '');
            if ($id === '' || $this->databaseRepository->find($id) !== null) {
                continue;
            }
            $this->databaseRepository->insertExisting($job);
            $count++;
        }

        return $count;
    }
}

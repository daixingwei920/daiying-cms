<?php

declare(strict_types=1);

namespace Cms\Core\Migration;

use PDO;

interface MigrationInterface
{
    public function id(): string;

    public function up(PDO $pdo): void;
}

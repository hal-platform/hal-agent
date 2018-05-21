#!/usr/bin/env php
<?php

namespace Hal\Bin;

use Doctrine\ORM\EntityManagerInterface;

use Hal\Core\Entity\Application;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\System\UserIdentityProvider;
use Hal\Core\Entity\System\VersionControlProvider;
use Hal\Core\Entity\User;
use Hal\Core\Entity\User\UserIdentity;
use Hal\Core\Entity\User\UserPermission;

/**
 * Run postgres within docker (ephemeral)
 * > docker run -d -p 80:80 -e "PGADMIN_DEFAULT_EMAIL=hal" -e "PGADMIN_DEFAULT_PASSWORD=hal" dpage/pgadmin4
 * > docker run -d -p 5432:5432 -e "POSTGRES_DB=hal" -e "POSTGRES_USER=hal" postgres:9.6
 */

$root = realpath(__DIR__ . '/../../');
putenv("HAL_ROOT=${root}");
if (!$container = @include "${root}/config/bootstrap.php") {
    echo "Failed to load symfony service container.\n";
    exit(1);
};

$seeder = require "${root}/vendor/hal/hal-core/seeds/bootstrap.php";

$em = $container->get(EntityManagerInterface::class);
$seeder($em);

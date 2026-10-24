<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Starter\Administration\AdministrationServiceProvider;
use Starter\Ai\AiServiceProvider;
use Starter\Console\ConsoleServiceProvider;
use Starter\Identity\IdentityServiceProvider;
use Starter\Knowledge\KnowledgeServiceProvider;

return [
    // Starter modules (packages/php/*), registered explicitly...
    AdministrationServiceProvider::class,
    IdentityServiceProvider::class,
    AiServiceProvider::class,
    KnowledgeServiceProvider::class,
    ConsoleServiceProvider::class,

    AppServiceProvider::class,
    FortifyServiceProvider::class,
];

<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Proxmox')
    ->toUseStrictTypes();

arch('live API resources and helpers stay independent of database models')
    ->expect(['Proxmox\Resources', 'Proxmox\Support'])
    ->not->toUse(['Illuminate\Database', 'Proxmox\Models']);

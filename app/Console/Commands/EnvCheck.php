<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RequiredEnvironment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('env:check')]
#[Description('Fail when a required production environment variable is missing')]
class EnvCheck extends Command
{
    public function handle(RequiredEnvironment $environment): int
    {
        $problems = $environment->problems();

        if ($problems === []) {
            $this->info('Entorno completo.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        $this->error('El contenedor no arranca: completa el .env de producción.');

        return self::FAILURE;
    }
}

<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        return $this->dirFromEnv('APP_CACHE_DIR', parent::getCacheDir());
    }

    public function getLogDir(): string
    {
        return $this->dirFromEnv('APP_LOG_DIR', parent::getLogDir());
    }

    private function dirFromEnv(string $name, string $default): string
    {
        $dir = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($dir) && '' !== $dir ? rtrim($dir, '/').'/'.$this->environment : $default;
    }
}

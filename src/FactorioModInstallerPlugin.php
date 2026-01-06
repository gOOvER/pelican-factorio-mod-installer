<?php

namespace gOOvER\FactorioModInstaller;

use Filament\Contracts\Plugin;
use Filament\Panel;

class FactorioModInstallerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'factorio-mod-installer';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/$id/Pages"), 
            "gOOvER\\FactorioModInstaller\\Filament\\$id\\Pages"
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return \app(static::class);
    }

    public static function get(): static
    {
        return \filament(\app(static::class)->getId());
    }
}

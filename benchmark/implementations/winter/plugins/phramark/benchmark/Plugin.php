<?php

namespace Phramark\Benchmark;

use Backend\Facades\Backend;
use System\Classes\PluginBase;

/**
 * Winter CMS adapter of the Phramark benchmark: a CMS component that renders
 * the shared category page (guest workload) and a backend controller with the
 * standard Form/List behaviors over a database-backed page model (admin
 * workload), so both request paths are Winter's own.
 */
class Plugin extends PluginBase
{
    public function pluginDetails(): array
    {
        return [
            'name' => 'Phramark benchmark',
            'description' => 'Category page component and page editor for the Phramark benchmark.',
            'author' => 'Phramark',
            'icon' => 'icon-tachometer',
        ];
    }

    public function registerComponents(): array
    {
        return [
            Components\Category::class => 'phramarkCategory',
        ];
    }

    public function registerNavigation(): array
    {
        return [
            'phramark' => [
                'label' => 'Benchmark pages',
                'url' => Backend::url('phramark/benchmark/pages'),
                'icon' => 'icon-tachometer',
                'order' => 500,
            ],
        ];
    }
}

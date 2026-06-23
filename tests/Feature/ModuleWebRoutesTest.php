<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ModuleWebRoutesTest extends TestCase
{
    public function test_api_only_modules_do_not_register_web_resource_routes(): void
    {
        $resources = [
            'bookings',
            'categories',
            'lockcodes',
            'payments',
            'toolimages',
            'tools',
            'users',
            'vendors',
        ];
        $actions = [
            'index',
            'create',
            'store',
            'show',
            'edit',
            'update',
            'destroy',
        ];

        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $this->assertFalse(Route::has("{$resource}.{$action}"));
            }
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Services\DemoSimulationService;
use Illuminate\Console\Command;

class SeedDemoSimulations extends Command
{
    protected $signature = 'transport:seed-demo-simulations';

    protected $description = 'Seed five demo driver accounts with assigned jeepneys and active trips on local Ilocos Norte routes';

    public function handle(DemoSimulationService $service): int
    {
        $this->info('Seeding five demo driver accounts and active trips on local routes...');

        $result = $service->seedDemoData();

        $this->info('Created or verified ' . count($result['drivers']) . ' demo driver accounts:');
        foreach ($result['drivers'] as $d) {
            $this->line(" - Driver: {$d['fullname']} ({$d['email']})");
        }

        $this->info('Created or verified ' . count($result['vehicles']) . ' jeepneys:');
        foreach ($result['vehicles'] as $v) {
            $this->line(" - Vehicle: Plate {$v['plate_number']} (Jeepney)");
        }

        $this->info('Created or verified ' . count($result['trips']) . ' active trips:');
        foreach ($result['trips'] as $t) {
            $this->line(" - Trip #{$t['trip_id']} on route {$t['route_name']}");
        }

        $this->info('Demo simulation setup complete. Real tracking remains separate.');

        return self::SUCCESS;
    }
}

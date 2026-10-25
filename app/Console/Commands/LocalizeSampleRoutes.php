<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocalizeSampleRoutes extends Command
{
    protected $signature = 'transport:localize-samples';

    protected $description = 'Replace known placeholder sample routes with Ilocos Norte terminal names, preserving IDs';

    public function handle(): int
    {
        if (! config('transport.sample_data')) {
            $this->error('This command is limited to databases marked TRANSPORT_SAMPLE_DATA=true.');

            return self::FAILURE;
        }
        try {
            DB::transaction(function () {
                foreach (config('sample_routes.routes') as $id => $sample) {
                    $row = DB::table('Routes')->where('route_id', $id)->lockForUpdate()->first();
                    if (! $row || ! in_array($row->route_name, [$sample['legacy'], $sample['route_name']], true)) {
                        throw new RuntimeException('Route '.$id.' is not a recognized sample record. No sample records were changed.');
                    }
                    DB::table('Routes')->where('route_id', $id)->update(['route_name' => $sample['route_name'], 'origin' => $sample['origin'], 'destination' => $sample['destination']]);
                }
                foreach (config('sample_routes.stops') as $id => $sample) {
                    $row = DB::table('Stops')->where('stop_id', $id)->lockForUpdate()->first();
                    if (! $row || (int) $row->route_id !== $sample['route_id'] || ! in_array($row->stop_name, [$sample['legacy'], $sample['stop_name']], true)) {
                        throw new RuntimeException('Stop '.$id.' is not a recognized sample record. No sample records were changed.');
                    }
                    $source = DB::table('Stops')->where('stop_id', $sample['coordinate_source'])->first();
                    if (! $source || (float) $source->latitude < 18 || (float) $source->latitude > 18.3 || (float) $source->longitude < 120.4 || (float) $source->longitude > 120.8) {
                        throw new RuntimeException('Local sample stop coordinates are missing or invalid. No sample records were changed.');
                    }
                    DB::table('Stops')->where('stop_id', $id)->update(['stop_name' => $sample['stop_name'], 'latitude' => $source->latitude, 'longitude' => $source->longitude]);
                }
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Localized 5 sample routes and 4 placeholder stops. Route/stop IDs and trip assignments were preserved.');

        return self::SUCCESS;
    }
}

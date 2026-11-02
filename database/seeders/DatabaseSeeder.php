<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Machine;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Account fixtures are intentionally NOT provisioned here: a default seeder must never
     * create Admin/operator accounts with a predictable password. Provision accounts through
     * an explicit, environment-scoped step instead.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Default PRIME fixtures may only be seeded in local or testing environments.');
        }

        $location = Location::query()->updateOrCreate(
            ['location_code' => 'LOC-PRD-A'],
            [
                'location_name' => 'Produksi A',
                'description' => 'Lokasi seed untuk flow auth dan QR',
                'is_active' => true,
            ],
        );

        Machine::query()->updateOrCreate(
            ['machine_code' => 'MCH-001'],
            [
                'location_id' => $location->id,
                'machine_name' => 'CNC Milling',
                'qr_token' => 'qr-mch-001',
                'description' => 'Mesin aktif untuk validasi login, QR, dan machine access.',
                'is_active' => true,
            ],
        );

        Machine::query()->updateOrCreate(
            ['machine_code' => 'MCH-002'],
            [
                'location_id' => $location->id,
                'machine_name' => 'Sealing Machine',
                'qr_token' => 'qr-mch-002',
                'description' => 'Mesin nonaktif untuk memastikan tombol aksi disabled.',
                'is_active' => false,
            ],
        );
    }
}

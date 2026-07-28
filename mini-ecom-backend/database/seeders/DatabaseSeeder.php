<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Ports `db/seed.sql`: reference data plus one fully worked order.
 *
 * Order matters — the worked order references seeded users, addresses, products and a
 * delivery slot.
 *
 * Deliberately *not* using WithoutModelEvents: HasPublicId assigns each row's UUIDv7 from a
 * `creating` hook, and suppressing model events would leave every seeded row without the
 * identifier the API addresses it by.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            CatalogSeeder::class,
            DeliverySlotSeeder::class,
            CartSeeder::class,
            WorkedOrderSeeder::class,
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\DeliverySlot;
use Database\Seeders\Concerns\SeedsPublicIds;
use Illuminate\Database\Seeder;

/**
 * Seven windows from `db/seed.sql`, deliberately including one already full (slot 4) and one
 * free of charge (slot 7) so both edge cases are reachable without hand-crafting data.
 */
class DeliverySlotSeeder extends Seeder
{
    use SeedsPublicIds;

    public function run(): void
    {
        $slots = [
            [1, '2026-07-21', '09:00', '11:00', 20, 1, 3.99],
            [2, '2026-08-03', '09:00', '11:00', 20, 0, 3.99],
            [3, '2026-08-03', '11:00', '13:00', 20, 4, 3.99],
            [4, '2026-08-03', '17:00', '19:00', 15, 15, 5.99],  // full
            [5, '2026-08-04', '09:00', '11:00', 20, 2, 3.99],
            [6, '2026-08-04', '17:00', '19:00', 15, 0, 5.99],
            [7, '2026-08-05', '09:00', '11:00', 20, 0, 0.00],   // free window
        ];

        foreach ($slots as [$id, $date, $from, $to, $capacity, $booked, $fee]) {
            (new DeliverySlot)->forceFill([
                'id' => $id,
                'public_id' => $this->publicId(5, $id),
                'slot_date' => $date,
                'starts_at' => "{$date} {$from}:00",
                'ends_at' => "{$date} {$to}:00",
                'capacity' => $capacity,
                'booked_count' => $booked,
                'fee' => $fee,
                'is_active' => true,
            ])->save();
        }
    }
}

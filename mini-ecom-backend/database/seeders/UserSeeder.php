<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\User;
use Database\Seeders\Concerns\SeedsPublicIds;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Two customers and one operator, from `db/seed.sql`. All three sign in with `password`.
 */
class UserSeeder extends Seeder
{
    use SeedsPublicIds;

    public function run(): void
    {
        $password = Hash::make('password');

        $users = [
            ['id' => 1, 'email' => 'alice@example.com', 'full_name' => 'Alice Nguyen', 'phone' => '+1-415-555-0142', 'role' => UserRole::Customer, 'email_verified_at' => '2026-06-01 09:15:00'],
            ['id' => 2, 'email' => 'ben@example.com', 'full_name' => 'Ben Okafor', 'phone' => '+1-415-555-0188', 'role' => UserRole::Customer, 'email_verified_at' => '2026-06-14 17:40:00'],
            ['id' => 3, 'email' => 'ops@grocerly.example', 'full_name' => 'Ops Admin', 'phone' => null, 'role' => UserRole::Admin, 'email_verified_at' => '2026-05-02 08:00:00'],
        ];

        foreach ($users as $attributes) {
            $id = $attributes['id'];
            unset($attributes['id']);

            $user = new User;
            $user->forceFill([
                'id' => $id,
                'public_id' => $this->publicId(1, $id),
                'password_hash' => $password,
                ...$attributes,
            ])->save();
        }

        $addresses = [
            ['id' => 1, 'user_id' => 1, 'label' => 'Home', 'recipient_name' => 'Alice Nguyen', 'phone' => '+1-415-555-0142', 'line1' => '1420 Sutter St', 'line2' => 'Apt 3B', 'city' => 'San Francisco', 'region' => 'CA', 'postal_code' => '94109', 'country_code' => 'US', 'latitude' => 37.7871230, 'longitude' => -122.4212340, 'delivery_notes' => 'Buzz 3B; leave with doorman if out.', 'is_default' => true],
            ['id' => 2, 'user_id' => 1, 'label' => 'Office', 'recipient_name' => 'Alice Nguyen', 'phone' => '+1-415-555-0142', 'line1' => '600 Montgomery St', 'line2' => 'Floor 12', 'city' => 'San Francisco', 'region' => 'CA', 'postal_code' => '94111', 'country_code' => 'US', 'latitude' => 37.7951980, 'longitude' => -122.4028760, 'delivery_notes' => 'Reception holds deliveries until 6pm.', 'is_default' => false],
            ['id' => 3, 'user_id' => 2, 'label' => 'Home', 'recipient_name' => 'Ben Okafor', 'phone' => '+1-415-555-0188', 'line1' => '88 Dolores St', 'line2' => null, 'city' => 'San Francisco', 'region' => 'CA', 'postal_code' => '94103', 'country_code' => 'US', 'latitude' => 37.7695440, 'longitude' => -122.4267890, 'delivery_notes' => null, 'is_default' => true],
        ];

        foreach ($addresses as $attributes) {
            $address = new Address;
            $address->forceFill([
                'public_id' => $this->publicId(2, $attributes['id']),
                ...$attributes,
            ])->save();
        }
    }
}

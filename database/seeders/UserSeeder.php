<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
			['email' => env('DUMMY_EMAIL')],
			[
				'name' => 'John Doe',
				'password' => bcrypt(env('DUMMY_PASSWORD')),
			]
		);
    }
}

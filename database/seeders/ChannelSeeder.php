<?php

namespace Database\Seeders;

use App\Models\Channel;
use Illuminate\Database\Seeder;

class ChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            ['name' => 'welcome',   'type' => 'announcement', 'section' => 'General', 'position' => 0, 'topic' => 'Rules and announcements'],
            ['name' => 'general',   'type' => 'text',         'section' => 'General', 'position' => 1, 'topic' => 'General conversation'],
            ['name' => 'off-topic', 'type' => 'text',         'section' => 'General', 'position' => 2, 'topic' => null],
            ['name' => 'Lounge',    'type' => 'voice',        'section' => 'Voice',   'position' => 3, 'topic' => null],
            ['name' => 'Gaming',    'type' => 'voice',        'section' => 'Voice',   'position' => 4, 'topic' => null],
        ];

        foreach ($channels as $data) {
            Channel::firstOrCreate(['name' => $data['name'], 'section' => $data['section']], $data);
        }
    }
}

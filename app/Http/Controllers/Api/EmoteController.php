<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EmoteController extends Controller
{
    public function index(): JsonResponse
    {
        $emotes = DB::table('custom_emotes')->orderBy('name')->get()->map(fn ($e) => [
            'name'     => $e->name,
            'url'      => asset('storage/emotes/' . $e->filename),
            'animated' => (bool) $e->animated,
        ]);

        return response()->json(['emotes' => $emotes]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member?->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'name' => ['required', 'regex:/^[a-z0-9_-]{2,32}$/'],
            'file' => ['required', 'file', 'mimes:gif,png,webp', 'max:512'],
        ]);

        $name     = strtolower($request->input('name'));
        $file     = $request->file('file');
        $ext      = strtolower($file->getClientOriginalExtension());
        $filename = $name . '.' . $ext;
        $animated = ($ext === 'gif');

        // Remove old file if replacing
        $existing = DB::table('custom_emotes')->where('name', $name)->first();
        if ($existing && $existing->filename !== $filename) {
            Storage::delete('public/emotes/' . $existing->filename);
        }

        Storage::makeDirectory('public/emotes');
        $file->storeAs('public/emotes', $filename);

        DB::table('custom_emotes')->updateOrInsert(
            ['name'       => $name],
            ['filename'   => $filename, 'animated' => $animated,
             'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json([
            'emote' => [
                'name'     => $name,
                'url'      => asset('storage/emotes/' . $filename),
                'animated' => $animated,
            ],
        ]);
    }

    public function destroy(Request $request, string $name): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member?->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $emote = DB::table('custom_emotes')->where('name', $name)->first();
        if (! $emote) {
            return response()->json(['message' => 'Emote not found.'], 404);
        }

        Storage::delete('public/emotes/' . $emote->filename);
        DB::table('custom_emotes')->where('name', $name)->delete();

        return response()->json(['ok' => true]);
    }
}

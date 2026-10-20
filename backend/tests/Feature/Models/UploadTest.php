<?php

use App\Models\Upload;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

it('prunes incomplete uploads older than the abandon window, with their chunks', function () {
    Storage::fake('local');
    $this->freezeTime();
    $abandoned = Upload::factory()->create(['created_at' => now()->subHours(24)]);
    Storage::disk('local')->put("chunks/{$abandoned->id}/0", 'data');

    $this->artisan('model:prune', ['--model' => [Upload::class]])->assertSuccessful();

    $this->assertModelMissing($abandoned);
    Storage::disk('local')->assertMissing("chunks/{$abandoned->id}");
});

it('keeps incomplete uploads that are still inside the abandon window', function () {
    Storage::fake('local');
    $this->freezeTime();
    $recent = Upload::factory()->create(['created_at' => now()->subHours(24)->addSecond()]);

    $this->artisan('model:prune', ['--model' => [Upload::class]])->assertSuccessful();

    $this->assertModelExists($recent);
});

it('never prunes completed uploads', function () {
    Storage::fake('local');
    $finished = Upload::factory()->uploaded()->create(['created_at' => now()->subYear()]);
    Storage::disk('local')->put($finished->path, 'media');

    $this->artisan('model:prune', ['--model' => [Upload::class]])->assertSuccessful();

    $this->assertModelExists($finished);
    Storage::disk('local')->assertExists($finished->path);
});

it('uses the configured abandon window', function () {
    Storage::fake('local');
    $this->freezeTime();
    config(['uploads.abandoned_after_hours' => 2]);
    $abandoned = Upload::factory()->create(['created_at' => now()->subHours(2)]);
    $recent = Upload::factory()->create(['created_at' => now()->subHour()]);

    $this->artisan('model:prune', ['--model' => [Upload::class]])->assertSuccessful();

    $this->assertModelMissing($abandoned);
    $this->assertModelExists($recent);
});

it('schedules pruning to run daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'model:prune'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 0 * * *');
});

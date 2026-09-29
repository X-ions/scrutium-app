<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = Str::slug(fake()->words(2, true)).'.jpg';

        return [
            'tenant_id' => fn () => Tenant::factory()->create()->id,
            'user_id' => fn (array $attributes) => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'filename' => $filename,
            'stored_filename' => Str::random(40).'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => fake()->numberBetween(50_000, 4_000_000),
            'width' => 1080,
            'height' => 1080,
            'duration' => null,
            'storage_disk' => 'local',
            'storage_path' => 'socialhub/'.Str::random(40).'.jpg',
            'thumbnail_path' => 'socialhub/thumbs/'.Str::random(40).'.jpg',
            'alt_text' => fake()->sentence(4),
            'tags' => [fake()->word()],
            'folder' => null,
            'metadata' => ['width' => 1080, 'height' => 1080],
            'usage_count' => 0,
            'last_used_at' => null,
        ];
    }

    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'mime_type' => 'image/jpeg',
            'width' => fake()->numberBetween(480, 4000),
            'height' => fake()->numberBetween(480, 4000),
            'duration' => null,
        ]);
    }

    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'filename' => Str::slug(fake()->words(2, true)).'.mp4',
            'mime_type' => 'video/mp4',
            'width' => 1920,
            'height' => 1080,
            'duration' => fake()->randomFloat(3, 1, 600),
        ]);
    }

    public function audio(): static
    {
        return $this->state(fn (array $attributes) => [
            'filename' => Str::slug(fake()->words(2, true)).'.mp3',
            'mime_type' => 'audio/mpeg',
            'width' => null,
            'height' => null,
            'duration' => fake()->randomFloat(3, 5, 600),
        ]);
    }

    public function document(): static
    {
        return $this->state(fn (array $attributes) => [
            'filename' => Str::slug(fake()->words(2, true)).'.pdf',
            'mime_type' => 'application/pdf',
            'width' => null,
            'height' => null,
            'duration' => null,
        ]);
    }

    public function inFolder(string $folder): static
    {
        return $this->state(fn (array $attributes) => [
            'folder' => $folder,
        ]);
    }
}

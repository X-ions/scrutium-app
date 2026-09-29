<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\PostMedia;
use App\Models\PostVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostMedia>
 */
class PostMediaFactory extends Factory
{
    protected $model = PostMedia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_variant_id' => fn () => PostVariant::factory()->create()->id,
            'media_asset_id' => fn () => MediaAsset::factory()->create()->id,
            'sort_order' => 0,
        ];
    }

    public function ordered(int $position): static
    {
        return $this->state(fn (array $attributes) => [
            'sort_order' => $position,
        ]);
    }
}

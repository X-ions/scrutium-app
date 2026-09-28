<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    /** @use HasFactory<\Database\Factories\TenantFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'domain',
        'plan',
        'timezone',
        'currency',
        'description',
        'logo_path',
        'name_updated_at',
        'language',
        'compact_layout',
        'auto_save',
    ];

<<<<<<< Updated upstream
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'name_updated_at' => 'datetime',
            'compact_layout' => 'boolean',
            'auto_save' => 'boolean',
        ];
=======
    public function setNameAttribute(string $value): void
    {
        $name = trim($value);
        $this->attributes['name'] = $name;
        $this->attributes['name_key'] = mb_strtolower($name);
>>>>>>> Stashed changes
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function influencers(): HasMany
    {
        return $this->hasMany(Influencer::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class);
    }

    public function contentPosts(): HasMany
    {
        return $this->hasMany(ContentPost::class);
    }

    public function scoreConfigs(): HasMany
    {
        return $this->hasMany(ScoreConfig::class);
    }

    public function influencerScores(): HasMany
    {
        return $this->hasMany(InfluencerScore::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

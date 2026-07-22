<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Operation extends Model
{
    protected $fillable = ['name', 'coverage_area', 'city', 'state', 'status', 'commercial_manager_id', 'delivery_manager_id'];

    public function commercialManager(): BelongsTo { return $this->belongsTo(User::class, 'commercial_manager_id'); }
    public function deliveryManager(): BelongsTo { return $this->belongsTo(User::class, 'delivery_manager_id'); }
    public function whatsappSession(): HasOne { return $this->hasOne(WhatsappSession::class); }
    public function companies(): HasMany { return $this->hasMany(Company::class); }
}

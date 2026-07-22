<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappSession extends Model
{
    protected $fillable = ['operation_id', 'provider', 'flowbridge_instance_id', 'phone_number', 'status', 'webhook_secret'];
    protected $hidden = ['webhook_secret'];
    public function operation(): BelongsTo { return $this->belongsTo(Operation::class); }
}

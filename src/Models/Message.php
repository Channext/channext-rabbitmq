<?php

namespace Channext\ChannextRabbitmq\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Message
 * @package App\Models
 */
class Message extends Model
{
    use SoftDeletes;

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'body' => 'array',
        'published' => 'boolean',
        'trace' => 'array'
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'delivery_mode',
        'routing_key',
        'body',
        'published',
        'trace_id',
        'trace'
    ];
}

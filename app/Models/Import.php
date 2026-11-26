<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Import extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'data_source_id',
        'user_id',
        'file_name',
        'file_path',
        'file_hash',
        'rows_read',
        'rows_imported',
        'rows_skipped',
        'status',
        'log',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'rows_read' => 'integer',
        'rows_imported' => 'integer',
        'rows_skipped' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

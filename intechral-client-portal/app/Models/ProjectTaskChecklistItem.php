<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTaskChecklistItem extends Model
{
    protected $fillable = ['task_id', 'title', 'completed', 'position'];

    protected $casts = ['completed' => 'boolean'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }
}

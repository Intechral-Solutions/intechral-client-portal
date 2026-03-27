<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskChecklistItem extends Model
{
    protected $table = 'task_checklist_items';

    protected $fillable = ['task_id', 'title', 'completed', 'position'];

    protected $casts = ['completed' => 'boolean'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}

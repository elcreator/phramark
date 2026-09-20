<?php

namespace Phramark\Benchmark\Models;

use Winter\Storm\Database\Model;

/**
 * A database-backed page for the admin workload: the same title/body pair
 * the other stacks edit, stored through Winter's Eloquent-based model layer.
 */
class Page extends Model
{
    use \Winter\Storm\Database\Traits\Validation;

    public $table = 'phramark_benchmark_pages';

    protected $fillable = ['title', 'content'];

    public $rules = [
        'title' => 'required',
    ];
}

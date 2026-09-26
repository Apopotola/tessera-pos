<?php

namespace Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;

/** A category, brand or item a promotion applies to. */
class PromotionTarget extends Model
{
    public $timestamps = false;

    protected $fillable = ['promotion_id', 'target_type', 'target_id'];
}

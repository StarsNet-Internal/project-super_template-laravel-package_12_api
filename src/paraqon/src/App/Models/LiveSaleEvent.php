<?php

namespace Starsnet\Project\Paraqon\App\Models;

use App\Models\Traits\ObjectIDTrait;
use MongoDB\Laravel\Eloquent\Model;

class LiveSaleEvent extends Model
{
    use ObjectIDTrait;

    protected $connection = 'mongodb';
    protected $collection = 'live_sale_events';

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];
}

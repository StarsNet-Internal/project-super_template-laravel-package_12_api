<?php

namespace Starsnet\Project\Paraqon\App\Models;

use App\Models\Traits\ObjectIDTrait;
use MongoDB\Laravel\Eloquent\Model;

class LiveSaleState extends Model
{
    use ObjectIDTrait;

    protected $connection = 'mongodb';
    protected $collection = 'live_sale_states';

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];
}

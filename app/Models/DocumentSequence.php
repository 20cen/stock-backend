<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['prefix', 'year', 'last_number'])]
class DocumentSequence extends Model {}

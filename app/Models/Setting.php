<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'admin_password_hash', 'kobo_token', 'public_base_url'])]
class Setting extends Model
{
    // Singleton row keyed by id = 1.
}

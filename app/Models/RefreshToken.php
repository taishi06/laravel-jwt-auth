<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'token_hash', 'expires_at', 'revoked_at'])]
class RefreshToken extends Model
{
    public function user()
	{
		return $this->belongsTo(User::class);
	}
}

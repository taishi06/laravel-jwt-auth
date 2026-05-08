# JWT Laravel Authentication

This project is an example on how to setup JWT as your authentication method for Laravel.

## Install Laravel

Run the laravel installation.

```bash
laravel new project_name
```

If you don't have yet laravel installer, feel free to download it via `composer`,

```bash
composer require global laravel/installer
```

Once the installer runs, it'll ask you a couple of questions such as the template; for this instance we will not use or set anything, select `None`.

It'll then ask for the Unit Testing;for this I've selected `Pest`.

Next, it will ask if you want to run the database migrations; for this I've selected No or later as I will would like to setup first the additional table/s, but you can run it as well and run the new table migrations afterwards when ready.

Lastly, it'll ask if you want to run `npm install` for node packages which is optional. Since this small project primary focuses on backend authentication, I would refuse to run it.

## Create the Refresh Tokens Table

Run a migration for a new table.

```bash
php artisan make:migration create_refresh_tokens_table
```

Open the new migration file and make sure to have this setup;

```php
Schema::create('refresh_tokens', function (Blueprint $table) {
    $table->id();

    $table->foreignId('user_id')
        ->constrained()
        ->onDelete('cascade');

    $table->string('token_hash');
    $table->timestamp('expires_at');
    $table->timestamp('revoked_at')->nullable();

    $table->timestamps();
});
```

After running the migration successfully, run the model creation command for the `refresh_tokens`.

```bash
php artisan make:model RefreshToken
```

## Install JWT Package

Install the JWT package we'll use.

```bash
composer require tymon/jwt-auth
```

Once installed, publish the config; this will add the JWT Config file at `config/jwt.php`

## User Model Configuration

We'll need to modify the `app/models/User.php`. Firstly, add the package on top of the Model's class.

```
use Tymon\JWTAuth\Contracts\JWTSubject;
```

Next is to add the 2 required methods `getJWTIdentifier` and `getJWTCustomClaims`. You should have a similar look on your model based on the code below;

```php
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Tymon\JWTAuth\Contracts\JWTSubject;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

	/**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
	public function getJWTIdentifier()
    {
        return $this->getKey();
    }

	/**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTCustomClaims()
    {
        return [];
    }
}
```

## Authorization Endpoints

Next is create the Auth endpoints that will handle `login`, `logout` and `refresh tokens`. Run the command below on your Terminal to create the controller.

```bash
php artisan make:controller AuthController
```

Once the command has been executed, locate the `app/Http/Controllers/AuthController.php` file and open it on your code editor. Add the `login` function which should make your file look like the code below;

```php
namespace App\Http\Controllers;

use App\Models\RefreshToken;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
	{
		// request credential input
		$credentials = $request->only('email', 'password');

		// validate the credentials
		if (!$token = Auth::attempt($credentials)) {
			return response()->json([
				'message' => 'Invalid credentials'
			], 401);
		}

		// generate a random refresh token
		$refreshToken = Str::random(64);

		// create a refresh token
		RefreshToken::create([
			'user_id' => Auth::id(),
			'token_hash' => Hash::make($refreshToken),
			'expires_at' => now()->addDays(7),
		]);

		// return access and refresh token
		return response()
			->json([
				'access_token' => $token,
				'token_type' => 'Bearer',
				'expires_in' => config('jwt.ttl') * 60 // minutes to seconds
			])
			->cookie(
				// name
				'refresh_token',
				// value
				$refreshToken,
				// 7 days
				60 * 24 * 7,
				// path
				'/',
				// domain
				url('/'),
				// secure
				true,
				// http only
				true
        );
	}
}
```

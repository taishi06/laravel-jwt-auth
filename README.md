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

After running the migration successfully, run the model creation command for the `refresh_tokens` which will create a `app/Models/RefreshToken.php` file.

```bash
php artisan make:model RefreshToken
```

Open the `RefreshToken` model and make sure that you have similar to the setup below;

```php
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
```

## Install the API routes

Run these command on your terminal to create and register the API routes. This will create a `routes/api.php` file.

```bash
php artisan install:api
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

### Endpoint: POST /api/login

Once the command has been executed, locate the `app/Http/Controllers/AuthController.php` file and open it on your code editor. Add the `login` function which should make your file look like the code below;

```php
namespace App\Http\Controllers;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
	/**
	 * Project Domain
	 *
	 * @var string
	 */
	protected $domain;

	public function __construct()
	{
		$this->domain = url('/');
	}

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

		// we have user
		$user = Auth::user();

		// invalidate old user refresh tokens
		RefreshToken::where('user_id', $user->id)
			->whereNull('revoked_at')
			->update(['revoked_at' => now()]);

		// generate a random refresh token
		$refreshToken = Str::random(64);

		// create a refresh token
		RefreshToken::create([
			'user_id' => $user->id,
			'token_hash' => Hash::make($refreshToken),
			'expires_at' => now()->addDays(7),
		]);

		// return access and refresh token
		return response()
			->json([
				'access_token' => $token,
				'refresh_token' => $refreshToken,
				'token_type' => 'Bearer',
				// minutes to seconds
				'expires_in' => config('jwt.ttl') * 60
			])
			->cookie(
				// name
				'refresh_token',
				// value
				$refreshToken,
				// expiration
				config('jwt.refresh_ttl'),
				// path
				'/',
				// domain
				$this->domain,
				// secure
				true,
				// http only
				true
        );
	}
}
```

### Endpoint: POST /api/refresh

We'll now need the `refresh` endpoint in order to replenish the user's Access Token and rotate a new refresh token.

```php
/**
 * Refresh token and invalidate the current refresh token
 */
public function refresh(Request $request)
{
	// get the refresh token cookie from the request
	$refreshToken = $request->cookie('refresh_token');
	if (!$refreshToken) {
		return response()->json([
			'message' => 'Invalid request.'
		], 422);
	}

	// get all refresh tokens that are still valid
	$storedTokens = RefreshToken::whereNull('revoked_at')
						->where('expires_at', '>', now())
						->get();

	// matched token holder
	$matchedToken = null;

	// check all mached token
	foreach ($storedTokens as $token) {
		if (Hash::check($refreshToken, $token->token_hash)) {
			$matchedToken = $token;
			break;
		}
	}

	// if we don't have a matched token, stop
	if (!$matchedToken) {
		return response()->json([
			'message' => 'Invalid refresh token'
		], 401);
	}

	// invalidate the matched token
	$matchedToken->update([
		'revoked_at' => now()
	]);

	// get the matched token's User ID
	$user = User::find($matchedToken->user_id);

	// prepare new access token
	$newAccessToken = Auth::login($user);

	// new refresh token for rotation
	$newRefreshToken = Str::random(64);

	// create new token
	RefreshToken::create([
		'user_id' => $user->id,
		'token_hash' => Hash::make($newRefreshToken),
		'expires_at' => now()->addDays(7),
	]);

	return response()
		->json([
			'access_token' => $newAccessToken,
			'refresh_token' => $newRefreshToken,
			'token_type' => 'Bearer',
			'expires_in' => config('jwt.ttl') * 60 // minutes to seconds
		])
		->cookie(
			'refresh_token',
			$newRefreshToken,
			config('jwt.refresh_ttl'),
			'/',
			$this->domain,
			true,
			true
		);
}
```

### Endpoint: POST /api/logout

This endpoint will revoke all refresh token and invalidate the access token.

```php
public function logout(Request $request)
{
	$refreshToken = $request->cookie('refresh_token');

	// get all valid refresh tokens
	// TODO: its ideal as well to perform jobs that will cleanup and expires_at <= now() and revoked_at = NULL
	$tokens = RefreshToken::whereNull('revoked_at')
				->where('expires_at', '>=', now())
				->get();

	// revoke refresh token of the user
	foreach ($tokens as $token) {
		if (Hash::check($refreshToken, $token->token_hash)) {
			$token->update([
				'revoked_at' => now()
			]);
		}
	}

	// logout session
	Auth::logout();

	return response()
		->json([
			'message' => 'Logged out'
		])
		->withoutCookie('refresh_token');
}
```

## Auth Configuration

Configure `config/auth.php` and change `guard` key to `api` or add `AUTH_GUARD` environment variable on `.env` file.

```php
'defaults' => [
	'guard' => env('AUTH_GUARD', 'web'),
	'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
],
```

Add an `api` key on the `guards` key where it then should look like from the code here;

```php
'guards' => [
	'web' => [
		'driver' => 'session',
		'provider' => 'users',
	],
],
```

to this;

```php
'guards' => [
	'web' => [
		'driver' => 'session',
		'provider' => 'users',
	],
	'api' => [
		'driver' => 'jwt',
		'provider' => 'users',
	],
],
```

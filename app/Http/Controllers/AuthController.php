<?php

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
}

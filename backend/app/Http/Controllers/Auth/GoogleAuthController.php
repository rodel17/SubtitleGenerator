<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\User as GoogleUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GoogleAuthController extends Controller
{
    /**
     * Send the browser to Google's sign-in page.
     */
    public function redirect(): SymfonyRedirectResponse
    {
        if (blank(config('services.google.client_id')) || blank(config('services.google.client_secret'))) {
            return $this->failed("Google sign-in isn't set up on the server yet.");
        }

        return Socialite::driver('google')->redirect();
    }

    /**
     * Google sends the browser back here. Sign the user in (creating them on
     * their first visit) and hand the SPA an API token in the URL fragment,
     * which browsers never send to a server.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->failed('Google sign-in was cancelled.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->failed('Your sign-in took too long or was started in another tab. Please try again.');
        } catch (RequestException) {
            return $this->failed("Google couldn't confirm your sign-in. Please try again.");
        }

        $token = $this->findOrCreateUser($googleUser)->createToken('google')->plainTextToken;

        return redirect()->away(config('app.frontend_url').'/auth/callback#token='.rawurlencode($token));
    }

    /**
     * Match by Google account first, then by email, and refresh the profile from Google.
     */
    private function findOrCreateUser(GoogleUser $googleUser): User
    {
        $user = User::query()->where('google_id', $googleUser->getId())->first()
            ?? User::query()->where('email', $googleUser->getEmail())->first()
            ?? new User;

        $user->fill([
            'google_id' => $googleUser->getId(),
            'name' => $googleUser->getName() ?: $googleUser->getEmail(),
            'email' => $googleUser->getEmail(),
            'avatar' => $googleUser->getAvatar(),
        ])->save();

        return $user;
    }

    /**
     * Back to the SPA's sign-in page, which shows the message.
     */
    private function failed(string $message): RedirectResponse
    {
        return redirect()->away(config('app.frontend_url').'/login?'.http_build_query(['error' => $message]));
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\Source\GithubAppClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class GithubConnectionController extends Controller
{
    public function redirect(Request $request, GithubAppClient $github): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('github_oauth_state', $state);

        return redirect()->away($github->authorizationUrl($state));
    }

    public function callback(Request $request, GithubAppClient $github): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string'], 'state' => ['required', 'string']]);
        abort_unless(hash_equals((string) $request->session()->pull('github_oauth_state'), (string) $request->input('state')), 403);
        $github->connect($request->user(), (string) $request->input('code'));

        return to_route('projects.index')->with('success', 'GitHub App collegata.');
    }

    public function destroy(Request $request, GithubAppClient $github): RedirectResponse
    {
        if ($request->user()->githubConnection) {
            $github->disconnect($request->user()->githubConnection);
        }

        return back()->with('success', 'Connessione GitHub rimossa.');
    }
}

<?php

namespace App\Services\Source;

use App\Models\GithubConnection;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GithubAppClient
{
    public function authorizationUrl(string $state): string
    {
        $clientId = (string) config('services.github_app.client_id');
        if ($clientId === '') {
            throw new RuntimeException('GitHub App non configurata.');
        }

        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => config('services.github_app.redirect'),
            'state' => $state,
        ]);
    }

    public function connect(User $user, string $code): GithubConnection
    {
        $response = Http::asForm()->acceptJson()->post('https://github.com/login/oauth/access_token', [
            'client_id' => config('services.github_app.client_id'),
            'client_secret' => config('services.github_app.client_secret'),
            'code' => $code,
            'redirect_uri' => config('services.github_app.redirect'),
        ]);
        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload) || ! is_string($payload['access_token'] ?? null)) {
            throw new RuntimeException('Connessione GitHub non riuscita: '.($payload['error_description'] ?? $response->reason()));
        }

        $profile = $this->requestWithToken($payload['access_token'], 'get', '/user')->json();
        if (! is_array($profile) || ! isset($profile['id'], $profile['login'])) {
            throw new RuntimeException('GitHub non ha restituito un profilo valido.');
        }

        return GithubConnection::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'github_user_id' => (string) $profile['id'],
                'login' => (string) $profile['login'],
                'access_token' => $payload['access_token'],
                'refresh_token' => $payload['refresh_token'] ?? null,
                'scopes' => $payload['scope'] ?? null,
                'expires_at' => isset($payload['expires_in']) ? now()->addSeconds((int) $payload['expires_in']) : null,
                'refresh_expires_at' => isset($payload['refresh_token_expires_in']) ? now()->addSeconds((int) $payload['refresh_token_expires_in']) : null,
                'revoked_at' => null,
            ],
        );
    }

    /** @return array{items: array<int, mixed>, page: int, has_more: bool} */
    public function repositories(GithubConnection $connection, int $page = 1): array
    {
        $response = $this->request($connection, 'get', '/user/repos', [
            'visibility' => 'all',
            'affiliation' => 'owner,collaborator,organization_member',
            'sort' => 'updated',
            'per_page' => 50,
            'page' => max(1, $page),
        ]);
        $items = $response->json();
        if (! is_array($items)) {
            throw new RuntimeException('Risposta repository GitHub non valida.');
        }

        return ['items' => $items, 'page' => max(1, $page), 'has_more' => count($items) === 50];
    }

    /** @return array<int, mixed> */
    public function branches(GithubConnection $connection, string $fullName, int $page = 1): array
    {
        $this->assertFullName($fullName);

        return (array) $this->request($connection, 'get', "/repos/{$fullName}/branches", [
            'per_page' => 50, 'page' => max(1, $page),
        ])->json();
    }

    /** @return array<int, mixed> */
    public function commits(GithubConnection $connection, string $fullName, ?string $sha = null, int $page = 1): array
    {
        $this->assertFullName($fullName);
        $query = ['per_page' => 50, 'page' => max(1, $page)];
        if ($sha) {
            $query['sha'] = $sha;
        }

        return (array) $this->request($connection, 'get', "/repos/{$fullName}/commits", $query)->json();
    }

    /** @return array{repository_id: string, full_name: string, sha: string} */
    public function resolveCommit(GithubConnection $connection, string $repositoryId, string $fullName, string $ref): array
    {
        $this->assertFullName($fullName);
        $repository = $this->request($connection, 'get', '/repositories/'.rawurlencode($repositoryId))->json();
        if (! is_array($repository) || (string) ($repository['id'] ?? '') !== $repositoryId || ($repository['full_name'] ?? null) !== $fullName) {
            throw new RuntimeException('Repository GitHub non autorizzato o identita non coerente.');
        }
        $commit = $this->request($connection, 'get', "/repos/{$fullName}/commits/".rawurlencode($ref))->json();
        $sha = is_array($commit) ? ($commit['sha'] ?? null) : null;
        if (! is_string($sha) || preg_match('/^[a-f0-9]{40}$/', $sha) !== 1) {
            throw new RuntimeException('GitHub non ha risolto il riferimento a un commit immutabile.');
        }

        return ['repository_id' => $repositoryId, 'full_name' => $fullName, 'sha' => $sha];
    }

    public function downloadCommit(GithubConnection $connection, string $repositoryId, string $fullName, string $sha, string $destination): void
    {
        $resolved = $this->resolveCommit($connection, $repositoryId, $fullName, $sha);
        if ($resolved['sha'] !== $sha) {
            throw new RuntimeException('Il commit GitHub risolto non coincide con quello accodato.');
        }
        $limit = (int) config('audits.imports.compressed_bytes');
        $client = new Client(['base_uri' => 'https://api.github.com', 'http_errors' => false]);
        $response = $client->request('GET', "/repos/{$fullName}/zipball/{$sha}", [
            'headers' => $this->headers($this->token($connection)),
            'sink' => $destination,
            'timeout' => 300,
            'allow_redirects' => true,
            'progress' => static function (int $total, int $downloaded) use ($limit): void {
                if ($total > $limit || $downloaded > $limit) {
                    throw new RuntimeException('Archivio GitHub superiore al limite compresso.');
                }
            },
        ]);
        if ($response->getStatusCode() >= 400) {
            @unlink($destination);
            throw new RuntimeException("Download archivio GitHub fallito [{$response->getStatusCode()}].");
        }
    }

    private function request(GithubConnection $connection, string $method, string $uri, array $query = []): Response
    {
        $response = $this->requestWithToken($this->token($connection), $method, $uri, $query);
        if ($response->status() === 401) {
            $connection->update(['revoked_at' => now()]);
            throw new RuntimeException('Accesso GitHub revocato: riconnetti la GitHub App.');
        }
        if ($response->status() === 403 && $response->header('X-RateLimit-Remaining') === '0') {
            throw new RuntimeException('Rate limit GitHub raggiunto. Riprova dopo il reset indicato da GitHub.');
        }
        if (! $response->successful()) {
            throw new RuntimeException("GitHub API [{$response->status()}]: ".($response->json('message') ?? $response->reason()));
        }

        return $response;
    }

    private function requestWithToken(string $token, string $method, string $uri, array $query = []): Response
    {
        return Http::withHeaders($this->headers($token))->send(strtoupper($method), 'https://api.github.com'.$uri, ['query' => $query]);
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'Lailaps',
        ];
    }

    private function token(GithubConnection $connection): string
    {
        if ($connection->revoked_at !== null) {
            throw new RuntimeException('Connessione GitHub revocata.');
        }
        if ($connection->expires_at === null || $connection->expires_at->isAfter(now()->addMinute())) {
            return $connection->access_token;
        }
        if (! $connection->refresh_token || ($connection->refresh_expires_at && $connection->refresh_expires_at->isPast())) {
            throw new RuntimeException('Connessione GitHub scaduta: riconnetti la GitHub App.');
        }
        $response = Http::asForm()->acceptJson()->post('https://github.com/login/oauth/access_token', [
            'client_id' => config('services.github_app.client_id'),
            'client_secret' => config('services.github_app.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
        ]);
        $payload = $response->json();
        if (! $response->successful() || ! is_string($payload['access_token'] ?? null)) {
            $connection->update(['revoked_at' => now()]);
            throw new RuntimeException('Refresh GitHub fallito: riconnetti la GitHub App.');
        }
        $connection->update([
            'access_token' => $payload['access_token'],
            'refresh_token' => $payload['refresh_token'] ?? $connection->refresh_token,
            'expires_at' => isset($payload['expires_in']) ? now()->addSeconds((int) $payload['expires_in']) : null,
            'refresh_expires_at' => isset($payload['refresh_token_expires_in']) ? now()->addSeconds((int) $payload['refresh_token_expires_in']) : $connection->refresh_expires_at,
            'revoked_at' => null,
        ]);

        return (string) $payload['access_token'];
    }

    private function assertFullName(string $fullName): void
    {
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $fullName) !== 1) {
            throw new RuntimeException('Nome repository GitHub non valido.');
        }
    }
}

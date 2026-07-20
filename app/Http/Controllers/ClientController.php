<?php

namespace App\Http\Controllers;

use App\BaseURL;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $clients = ClientResource::collection($request->user()->clients()->paginate(15));

        return Inertia::render('Client/Index', ['clients' => $clients, 'base_urls' => BaseURL::cases()]);
    }

    /**
     * Test central credentials endpoint
     */
    public function testCentralCreds(Request $request, Client $client)
    {
        if ($request->user()->cannot('update', $client)) {
            abort(403);
        }

        $type = $request->validate([
            'type' => ['required', Rule::in(['central', 'classic'])],
        ])['type'];

        if ($type === 'classic') {
            if (! $client->hasClassicCentralCredentials()) {
                return to_route('clients.index')->with('error', 'Classic credentials are not configured for this client.');
            }

            if (! $client->handleClassicBearerToken(true)) {
                return to_route('clients.index')->with('error', 'Failed to validate Classic Central credentials.');
            }

            return to_route('clients.index')->with('success', 'Classic Central credentials validated successfully.');
        }

        if (! $client->handleBearerTokenAuth(true)) {
            return to_route('clients.index')->with('error', 'Failed to validate Central credentials.');
        }

        return to_route('clients.index')->with('success', 'Central credentials validated successfully.');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:3|max:255',
            'client_id' => 'required|string|min:12|max:255',
            'client_secret' => 'required|string|min:12|max:255',
            'customer_id' => 'required|string|min:12|max:255',
            'base_url' => ['required', Rule::enum(BaseURL::class)],
        ]);

        $access_token = CentralController::getAccessToken($data['client_id'], $data['client_secret']);

        if ($access_token === 'failed_to_get_token') {
            return back()->withErrors(['failed_to_get_token' => 'Failed to get access token from central.']);
        } else {
            $client_data = array_merge($data, ['bearer_token' => $access_token]);

            if ($request->classic_client_id !== null) {
                $classic_central_data = $request->validate([
                    'classic_client_id' => 'required|string|min:12|max:255',
                    'classic_client_secret' => 'required|string|min:12|max:255',
                    'classic_username' => 'required|string|min:8|max:255',
                    'classic_password' => 'required|string|min:8|max:255',
                ]);
                $classic_base_url = $this->mapClassicBaseUrl($client_data['base_url']);
                $client_data = array_merge($client_data, $classic_central_data, ['classic_base_url' => $classic_base_url]);
            }
            $request->user()->clients()->create($client_data);
            if ($request->user()->clients()->count() == 1) {
                $request->user()->clients()->first()->update(['current' => true]);
            }

            return back()->with('success', 'Client created successfully.');
        }
    }

    public function mapClassicBaseUrl(string $base_url): ?string
    {
        return BaseURL::from($base_url)->toClassicBaseUrl()?->value;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Client $client)
    {
        if ($request->user()->cannot('update', $client)) {
            abort(403);
        }

        $validated = [];
        if ($request->has('name')) {
            $validated = array_merge($validated, $request->validate(['name' => 'string|min:3|max:255']));
        }
        if ($request->has('client_id')) {
            $validated = array_merge($validated, $request->validate(['client_id' => 'string|min:12|max:255']));
        }
        if ($request->has('client_secret')) {
            $validated = array_merge($validated, $request->validate(['client_secret' => 'string|min:12|max:255']));
        }
        if ($request->has('customer_id')) {
            $validated = array_merge($validated, $request->validate(['customer_id' => 'string|min:12|max:255']));
        }
        if ($request->has('base_url')) {
            $validated = array_merge($validated, $request->validate(['base_url' => 'string']));
        }

        if ($request->has('classic_refresh_token') || $request->has('classic_access_token') || $request->has('classic_client_id')) {
            $validated = $request->validate([
                'classic_client_id' => 'sometimes|required|string|min:12|max:255',
                'classic_refresh_token' => 'sometimes|required|string|min:12|max:65535',
                'classic_access_token' => 'sometimes|required|string|min:12|max:65535',
            ]);

            $classicClientId = $validated['classic_client_id'] ?? null;
            $classicRefreshToken = $validated['classic_refresh_token'] ?? null;
            $classicAccessToken = $validated['classic_access_token'] ?? null;

            if ($classicClientId !== null) {
                $client->update(['classic_client_id' => $classicClientId]);
            }

            if ($classicRefreshToken !== null || $classicAccessToken !== null) {
                if (! $client->updateClassicCentralTokens($classicRefreshToken, $classicAccessToken)) {
                    return to_route('clients.index')->with(
                        'error',
                        'Failed to validate Classic Central tokens with Central.',
                    );
                }

                return to_route('clients.index')->with(
                    'success',
                    'Classic Central tokens saved and validated.',
                );
            }

            return to_route('clients.index')->with(
                'success',
                'Classic client ID saved.',
            );
        }

        if ($request->has('classic_webhook_secret') || $request->has('classic_webhook_wid') || $request->boolean('clear_classic_webhook_secret')) {
            $validated = $request->validate([
                'classic_webhook_secret' => 'sometimes|nullable|string|max:65535',
                'classic_webhook_wid' => 'sometimes|nullable|string|max:255',
                'clear_classic_webhook_secret' => 'sometimes|boolean',
            ]);

            $updates = [];

            if ($request->boolean('clear_classic_webhook_secret')) {
                $updates['classic_webhook_secret'] = null;
            } elseif (array_key_exists('classic_webhook_secret', $validated)) {
                $secret = trim((string) ($validated['classic_webhook_secret'] ?? ''));
                $updates['classic_webhook_secret'] = $secret !== '' ? $secret : null;
            }

            if (array_key_exists('classic_webhook_wid', $validated)) {
                $wid = trim((string) ($validated['classic_webhook_wid'] ?? ''));
                $updates['classic_webhook_wid'] = $wid !== '' ? $wid : null;
            }

            if ($updates !== []) {
                $client->update($updates);
            }

            return to_route('clients.index')->with(
                'success',
                'Classic Central webhook settings saved.',
            );
        }

        $client->update($validated);
        session()->flash('success', 'Client updated successfully.');

        return to_route('clients.index');
    }

    /**
     * Updates the client to be the current client
     */
    public function updateCurrent(Request $request, Client $client)
    {
        if ($request->user()->cannot('update', $client)) {
            abort(403);
        }
        $oldCurrentClient = $request->user()->clients()->where('current', true)->first();
        if ($oldCurrentClient) {
            $oldCurrentClient->update(['current' => false]);
        }
        $client->update(['current' => true]);

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Client $client)
    {
        if ($request->user()->cannot('delete', $client)) {
            abort(403);
        }
        if ($client->current) {
            $next = $request->user()->clients()->whereKeyNot($client->getKey())->first();
            if ($next) {
                $next->update(['current' => true]);
            }
        }
        $client->delete();
        session()->flash('success', 'Client deleted successfully.');

        return back();
    }
}

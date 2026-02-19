<?php

namespace App\Http\Controllers;

use App\BaseURL;
use App\Http\Controllers\CentralController;
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
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Test central credentials endpoint
     */
    public function testCentralCreds(Request $request, Client $client)
    {
        $data = $request->validate([
            'name' => 'required|string|min:3|max:255',
            'client_id' => 'required|string|min:12|max:255',
            'client_secret' => 'required|string|min:12|max:255',
            'customer_id' => 'required|string|min:12|max:255',
            'base_url' => ['required', Rule::enum(BaseURL::class)],
        ]);

        $access_token = CentralController::getAccessToken($data['client_id'], $data['client_secret']);

        if($access_token === 'failed_to_get_token') {
            Inertia::flash('error', 'Failed to get access token from central.');
            return back();
        }

        array_merge($data, ['bearer_token' => $access_token]);
        Inertia::flash('success', 'Successfully got access token from central. Client created successfully.');
        return to_route('clients.index');
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

        if($access_token === 'failed_to_get_token') {
            return back()->withErrors(['failed_to_get_token' => 'Failed to get access token from central.']);
        }

        else {
            $data = array_merge($data, ['bearer_token' => $access_token]);

            $request->user()->clients()->create($data);
            Inertia::flash('success', 'Client created successfully.');
            return back();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Client $client)
    {

    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Client $client)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Client $client)
    {
        if($request->user()->cannot('update', $client)) {
            abort(403);
        }

        $validated = [];
        if($request->has('name'))
            $validated = array_merge($validated, $request->validate(['name' => 'string|min:3|max:255']));
        if($request->has('client_id'))
            $validated = array_merge($validated, $request->validate(['client_id' => 'string|min:12|max:255']));
        if($request->has('client_secret'))
            $validated = array_merge($validated, $request->validate(['client_secret' => 'string|min:12|max:255']));
        if($request->has('customer_id'))
            $validated = array_merge($validated, $request->validate(['customer_id' => 'string|min:12|max:255']));
        if($request->has('base_url'))
            $validated = array_merge($validated, $request->validate(['base_url' => 'string']));

        $client->update($validated);
        Inertia::flash('success', 'Client updated successfully.');
        return to_route('clients.index');
    }

    /**
     * Updates the client to be the current client
     */
    public function updateCurrent(Request $request, Client $client)
    {
        if($request->user()->cannot('update', $client)) {
            abort(403);
        }
        $oldCurrentClient = $request->user()->clients()->where('current', true)->first();
        if($oldCurrentClient) {
            $oldCurrentClient->update(['current' => false]);
        }
        $client->update(['current' => true]);
        Inertia::share('currentClient', $client);
        return to_route('clients.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Client $client)
    {
        if($request->user()->cannot('delete', $client)) {
            abort(403);
        }
        $client->delete();
        Inertia::flash('success', 'Client deleted successfully.');
        return back();
    }
}

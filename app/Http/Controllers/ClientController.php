<?php

namespace App\Http\Controllers;

use App\BaseURL;
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
        $clients = $request->user()->clients()->paginate(15);
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

        $request->user()->clients()->create($data);
        Inertia::flash('success', 'Client created successfully.');
        return back();
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

        $validated = $request->validate([
            'name' => 'nullable|string|min:3|max:255',
            'client_id' => 'nullable|string|min:12|max:255',
            'client_secret' => 'nullable|string|min:12|max:255',
            'customer_id' => 'nullable|string|min:12|max:255',
            'base_url' => 'string',
        ]);

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
        $request->user()->clients()->update(['current' => false]);
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

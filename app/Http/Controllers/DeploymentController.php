<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeploymentController extends Controller
{
    public function index(Request $request)
    {
        $currentClient= $request->user()->clients->where('current', true)->first();

        if(!$currentClient) {
            Inertia::flash('error', 'Please set current client to view deployments');
            return to_route('clients.index');
        }

        $deployments = $currentClient->deployments()->withCount('devices')->get();

        return Inertia::render('Deployment/Index', [
            'deployments' => $deployments,
            ]);
    }

    public function show(Request $request, Deployment $deployment)
    {
        $deployment->load('devices');
        return Inertia::render('Deployment/Show', [
            'deployment' => $deployment,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:3|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        if (Deployment::where('name', $data['name'])->exists()) {
            return redirect()->back()->withErrors(['name' => 'Deployment with this name already exists']);
        }

        $client_id = $request->user()->currentClient()->id;

        Deployment::create([
            ...$data,
            'client_id' => $client_id
        ]);

        return redirect()->route('deployments.index');
    }

    public function destroy(Request $request, Deployment $deployment)
    {
        if($request->user()->cannot('delete', $deployment)) {
            abort(403);
        }
        $deployment->delete();
        return redirect()->route('deployments.index');
    }
}

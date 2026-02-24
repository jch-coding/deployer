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

        $deployments = $currentClient->deployments;

        return Inertia::render('Deployment/Index', [
            'deployments' => $deployments,
            ]);
    }

    public function show(Request $request, Deployment $deployment)
    {
        $devices = $deployment->devices;
        return Inertia::render('Deployment/Show', [
            'deployment' => $deployment,
            'devices' => $devices,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:3|max:255',
            'description' => 'nullable|string|max:255',
            'client_id' => 'required',
        ]);

        if (Deployment::where('name', $data['name'])->exists()) {
            return redirect()->back()->withErrors(['name' => 'Deployment with this name already exists']);
        }

        $client_id = (int) $data['client_id'];
        if($request->user()->clients->where('id', $client_id)->count() == 0) {
            return redirect()->back()->withErrors(['client_id' => 'Invalid client id']);
        }

        Deployment::create($data);

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

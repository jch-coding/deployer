<?php

namespace App\Http\Controllers;

use App\Models\CentralStreamEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CentralStreamEventController extends Controller
{
    public function index(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to view streaming messages');

            return to_route('clients.index');
        }

        $events = CentralStreamEvent::query()
            ->where('client_id', $currentClient->id)
            ->orderByDesc('id')
            ->paginate(50)
            ->through(fn (CentralStreamEvent $event) => $event->toMonitorRow());

        return Inertia::render('WebSocket/Index', [
            'events' => $events,
        ]);
    }
}

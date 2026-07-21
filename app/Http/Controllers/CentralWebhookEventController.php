<?php

namespace App\Http\Controllers;

use App\Models\CentralWebhookEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CentralWebhookEventController extends Controller
{
    public function index(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to view webhooks');

            return to_route('clients.index');
        }

        $events = CentralWebhookEvent::query()
            ->where('client_id', $currentClient->id)
            ->orderByDesc('id')
            ->paginate(50)
            ->through(fn (CentralWebhookEvent $event) => $event->toMonitorRow());

        return Inertia::render('Webhook/Index', [
            'events' => $events,
        ]);
    }
}

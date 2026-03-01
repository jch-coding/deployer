<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Device;

class DeviceGetScopeIdEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message = 'Getting scope-id from Central';

    /**
     * Create a new event instance.
     */
    public function __construct(public Device $device)
    {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('devices.scope_id.' . $this->device->name),
        ];
    }
}

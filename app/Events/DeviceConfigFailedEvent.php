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
class DeviceConfigFailedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message = 'Failed to configure device in Central';
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
            new PrivateChannel('devices.config_failed.' . $this->device->name),
        ];
    }
}

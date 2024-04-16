<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Support\Http;

class ChatReceipt extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id'                                           => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                                         => $this->when(Http::isInternalRequest(), $this->uuid),
            'chat_message_uuid'                            => $this->when(Http::isInternalRequest(), $this->chat_message_uuid, $this->chatMessage ? $this->chatMessage->public_id : null),
            'participant_uuid'                             => $this->when(Http::isInternalRequest(), $this->participant_uuid, $this->participant ? $this->participant->public_id : null),
            'participant_name'                             => $this->participant_name,
            'read_at'                                      => $this->read_at,
            'updated_at'                                   => $this->updated_at,
            'created_at'                                   => $this->created_at,
            'deleted_at'                                   => $this->deleted_at,
        ];
    }
}
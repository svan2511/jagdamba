<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appointment_date' => $this->appointment_date->format('Y-m-d'),
            'appointment_time' => $this->appointment_time->format('H:i'),
            'type' => $this->type,
            'status' => $this->status,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'cancelled_at' => $this->cancelled_at?->format('Y-m-d H:i:s'),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'doctor' => $this->doctor ? [
                'id' => $this->doctor->id,
                'specialty' => $this->doctor->specialty,
                'image' => $this->doctor->image,
                'user' => [
                    'id' => $this->doctor->user->id,
                    'name' => $this->doctor->user->name,
                ],
            ] : null,
            'patient' => $this->patient ? [
                'id' => $this->patient->id,
                'user' => [
                    'id' => $this->patient->user->id,
                    'name' => $this->patient->user->name,
                    'phone' => $this->patient->user->phone,
                ],
            ] : null,
        ];
    }
}
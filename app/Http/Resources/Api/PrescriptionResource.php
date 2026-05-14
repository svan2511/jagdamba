<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'diagnosis' => $this->diagnosis,
            'symptoms' => $this->symptoms,
            'medications' => $this->medications,
            'instructions' => $this->instructions,
            'follow_up_date' => $this->follow_up_date?->format('Y-m-d'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'doctor' => $this->doctor ? [
                'id' => $this->doctor->id,
                'specialty' => $this->doctor->specialty,
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
                ],
            ] : null,
            'appointment' => $this->appointment ? [
                'id' => $this->appointment->id,
                'appointment_date' => $this->appointment->appointment_date->format('Y-m-d'),
                'appointment_time' => $this->appointment->appointment_time->format('H:i'),
            ] : null,
        ];
    }
}
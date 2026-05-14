<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            // Include role-specific data
            'doctor' => $this->when($this->isDoctor() && $this->doctor, function() {
                $imageUrl = $this->doctor->image ? url($this->doctor->image) : null;
                return [
                    'id' => $this->doctor->id,
                    'specialty' => $this->doctor->specialty,
                    'qualification' => $this->doctor->qualification,
                    'experience_years' => $this->doctor->experience_years,
                    'bio' => $this->doctor->bio,
                    'image' => $imageUrl,
                    'consultation_fee' => $this->doctor->consultation_fee,
                    'available_days' => $this->doctor->available_days,
                    'start_time' => $this->doctor->start_time,
                    'end_time' => $this->doctor->end_time,
                    'is_available' => $this->doctor->is_available,
                    'is_verified' => $this->doctor->is_verified,
                    'average_rating' => round($this->doctor->average_rating ?? 0, 1),
                ];
            }),
            'patient' => $this->when($this->isPatient() && $this->patient, function() {
                return new PatientResource($this->patient);
            }),
        ];
    }
}
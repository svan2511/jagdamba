<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageUrl = $this->image ? url($this->image) : null;

        return [
            'id' => $this->id,
            'specialty' => $this->specialty,
            'qualification' => $this->qualification,
            'experience_years' => $this->experience_years,
            'bio' => $this->bio,
            'image' => $imageUrl,
            'consultation_fee' => $this->consultation_fee,
            'available_days' => $this->available_days,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'is_available' => $this->is_available,
            'is_verified' => $this->is_verified,
            'average_rating' => round($this->average_rating, 1),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'phone' => $this->user->phone,
                    'is_active' => $this->user->is_active,
                ];
            }),
        ];
    }
}
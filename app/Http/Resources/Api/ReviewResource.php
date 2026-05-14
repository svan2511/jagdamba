<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'is_approved' => $this->is_approved,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'patient' => $this->patient ? [
                'id' => $this->patient->id,
                'user' => [
                    'id' => $this->patient->user->id,
                    'name' => $this->patient->user->name,
                ],
            ] : null,
            'doctor' => $this->doctor ? [
                'id' => $this->doctor->id,
                'specialty' => $this->doctor->specialty ?? '',
                'user' => $this->doctor->user ? [
                    'id' => $this->doctor->user->id,
                    'name' => $this->doctor->user->name,
                ] : null,
            ] : null,
        ];
    }
}
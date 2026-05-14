<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'report_type' => $this->report_type,
            'file_path' => $this->file_path,
            'description' => $this->description,
            'report_date' => $this->report_date->format('Y-m-d'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'patient' => $this->patient ? [
                'id' => $this->patient->id,
                'user' => [
                    'id' => $this->patient->user->id,
                    'name' => $this->patient->user->name,
                ],
            ] : null,
        ];
    }
}
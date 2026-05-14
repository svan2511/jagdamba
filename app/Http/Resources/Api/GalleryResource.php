<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GalleryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'image' => $this->image,
            'url' => $this->image ? asset($this->image) : null,
            'category' => $this->category,
            'type' => $this->image && preg_match('/\.(mp4|webm|avi)$/i', $this->image) ? 'video' : 'image',
            'is_active' => $this->is_active,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
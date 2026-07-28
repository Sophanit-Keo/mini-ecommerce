<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'parentId' => $this->whenLoaded('parent', fn () => $this->parent?->public_id, null),
            'description' => $this->description,
            'imageUrl' => $this->image_url,
            'position' => $this->position,
            // Loaded with withCount() at the query, never per row — otherwise listing ten
            // categories costs eleven queries.
            'productCount' => $this->whenCounted('products'),
            // Present only when tree=true or on the detail endpoint.
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}

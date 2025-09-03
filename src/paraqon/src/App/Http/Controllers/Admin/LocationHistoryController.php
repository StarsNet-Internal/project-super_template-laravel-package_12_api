<?php

namespace StarsNet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Models
use App\Models\Product;
use StarsNet\Project\Paraqon\App\Models\LocationHistory;

class LocationHistoryController extends Controller
{
    public function getAllLocationHistories(Request $request): Collection
    {
        $productId = $request->input('product_id');
        return LocationHistory::when($productId, function ($query, $productId) {
            return $query->where('product_id', $productId);
        })
            ->get();
    }

    public function createHistory(Request $request): array
    {
        $product = Product::find($request->route('product_id'));

        // Create History
        $history = LocationHistory::create($request->all());
        $history->associateProduct($product);

        return [
            'message' => 'Success',
            'history' => $history
        ];
    }

    public function massUpdateLocationHistories(Request $request): array
    {
        foreach ($request->histories as $history) {
            $locationHistory = LocationHistory::find($history['id']);

            // Check if the history exists
            if (!is_null($locationHistory)) {
                $updateAttributes = $history;
                unset($updateAttributes['id']);
                $locationHistory->update($updateAttributes);
            }
        }

        return ['message' => 'Location Histories updated successfully'];
    }
}

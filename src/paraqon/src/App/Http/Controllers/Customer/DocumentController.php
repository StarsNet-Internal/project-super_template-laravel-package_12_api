<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;

// Enums
use App\Enums\Status;

// Models
use Starsnet\Project\Paraqon\App\Models\Document;

class DocumentController extends Controller
{
    public function createDocument(Request $request): array
    {
        /** @var Document $document */
        $document = Document::create($request->all());

        return [
            'message' => 'Created new Document successfully',
            'id' => $document->id
        ];
    }

    public function getAllDocuments(Request $request): Collection
    {
        // Exclude pagination/sorting params before filtering
        $filterParams = Arr::except($request->query(), ['per_page', 'page', 'sort_by', 'sort_order']);

        $query = Document::where('customer_id', $this->customer()->id)
            ->where('status', '!=', Status::DELETED->value);

        foreach ($filterParams as $key => $value) {
            $query->where($key, $value);
        }

        return $query->get();
    }

    public function getDocumentDetails(Request $request): Document
    {
        /** @var ?Document $document */
        $document = Document::find($request->route('id'));
        if (is_null($document)) abort(404, 'Document not found');

        return $document;
    }

    public function updateDocumentDetails(Request $request): array
    {
        /** @var ?Document $document */
        $document = Document::find($request->route('id'));
        if (is_null($document)) abort(404, 'Document not found');

        $document->update($request->all());

        return ['message' => 'Updated Document successfully'];
    }
}

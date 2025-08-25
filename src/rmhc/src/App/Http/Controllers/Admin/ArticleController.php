<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use App\Models\Article;

class ArticleController extends Controller
{
    public function massUpdateArticles(Request $request): array
    {
        $articleAttrbutes = $request->articles;

        foreach ($articleAttrbutes as $attributes) {
            /** @var ?Article $article */
            $article = Article::find($attributes['id']);

            // Check if the Article exists
            if (!is_null($article)) {
                $updateAttributes = $attributes;
                unset($updateAttributes['id']);
                $article->update($updateAttributes);
            }
        }

        return ['message' => 'Articles updated successfully'];
    }
}

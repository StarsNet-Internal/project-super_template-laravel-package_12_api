<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

use App\Http\Controllers\Customer\PostController as BasePostController;
use App\Enums\Status;
use App\Models\Configuration;
use App\Models\Post;
use App\Models\PostCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Starsnet\Project\Paraqon\App\Http\Controllers\Concerns\HandlesSubscriberPosts;
use Starsnet\Project\Paraqon\App\Http\Controllers\Concerns\ReadsParaqonConfiguration;

/**
 * Paraqon customer posts API — subscription gate for requires_subscription posts.
 * tcg-bid continues using base /api/customer/posts without this gate.
 */
class PostController extends BasePostController
{
    use HandlesSubscriberPosts,
        ReadsParaqonConfiguration;

    public function filterPostsByCategories(Request $request)
    {
        $categoryIDs = (array) ($request->category_ids ?? []);

        if ($request->has('slug')) {
            $sortingSlug = $request->slug;
            $sortingConfig = Configuration::where('slug', 'post-sorting')->latest()->first();

            if (!is_null($sortingConfig)) {
                $foundItem = collect($sortingConfig->sorting_list)->firstWhere('slug', $sortingSlug);

                if (
                    !is_null($foundItem) &&
                    isset($foundItem['value']['key'], $foundItem['value']['ordering'])
                ) {
                    $request['sort_by'] = $foundItem['value']['key'];
                    $request['sort_order'] = $foundItem['value']['ordering'];
                }
            }
        }

        $postIDs = Post::when($categoryIDs, function ($query, $categoryIDs) {
            return $query->whereHas('categories', function ($query) use ($categoryIDs) {
                $query->objectIDs($categoryIDs);
            });
        })
            ->when(!$this->canViewSubscriberPost(), function ($query) {
                return $this->applySubscriberPostVisibility($query);
            })
            ->statusActive()
            ->pluck('id')
            ->all();

        if (count($postIDs) === 0) {
            return new Collection();
        }

        return $this->getPostsWithLikedAndCommentCountForParaqon($postIDs);
    }

    public function getPostDetails(Request $request): Post
    {
        /** @var ?Post $post */
        $post = Post::find($request->route('id'));
        if (is_null($post)) {
            abort(404, 'Post not found');
        }
        if ($post->status !== Status::ACTIVE->value) {
            abort(404, 'Post is not available for public');
        }

        $this->assertCanViewPost($post);

        $post->is_liked = Auth::check()
            ? in_array($post->id, $this->account()?->liked_post_ids ?? [])
            : false;

        return $post;
    }

    public function getAllLikedPosts(): Collection
    {
        $account = $this->account();
        if (count($account->liked_post_ids) === 0) {
            return new Collection();
        }

        $postIDs = $this->filterAllowedPostIds($account->liked_post_ids);
        if (count($postIDs) === 0) {
            return new Collection();
        }

        return $this->getPostsWithLikedAndCommentCountForParaqon($postIDs);
    }

    public function getPostsByIDs(Request $request): Collection
    {
        $ids = $this->filterAllowedPostIds((array) ($request->input('ids', [])));
        if (count($ids) === 0) {
            return new Collection();
        }

        $request->merge(['ids' => $ids]);

        return parent::getPostsByIDs($request);
    }

    public function likeAndUnlikePost(Request $request): array
    {
        /** @var ?Post $post */
        $post = Post::find($request->route('id'));
        if (is_null($post)) {
            abort(404, 'Post not found');
        }
        if ($post->status !== Status::ACTIVE->value) {
            abort(404, 'Post is not available for public');
        }

        $this->assertCanViewPost($post);

        return parent::likeAndUnlikePost($request);
    }

    public function getPostReviews(Request $request): Collection
    {
        /** @var ?Post $post */
        $post = Post::find($request->route('id'));
        if (is_null($post)) {
            abort(404, 'Post not found');
        }
        if ($post->status !== Status::ACTIVE->value) {
            abort(404, 'Post is not available for public');
        }

        $this->assertCanViewPost($post);

        return parent::getPostReviews($request);
    }

    public function getRelatedPostsUrls(Request $request): array
    {
        $postID = $request->input('post_id');
        $excludedPostIDs = (array) $request->input('exclude_ids');
        $itemsPerPage = $request->input('items_per_page');

        $excludedPostIDs[] = $postID;
        $posts = [];

        $systemCategory = PostCategory::where('slug', 'recommended-posts')->first();
        if ($systemCategory) {
            $recommendedPosts = $this->applySubscriberPostVisibility(
                $systemCategory->posts()->statusActive()->excludeIDs($excludedPostIDs)
            )->get()->shuffle();

            $posts = array_merge($posts, $recommendedPosts->all());
            $excludedPostIDs = array_merge($excludedPostIDs, $recommendedPosts->pluck('id')->all());
        }

        $post = Post::find($postID);
        if ($post) {
            $this->assertCanViewPost($post);

            $categoryIDs = $post->categories()->statusActive()->pluck('id')->all();

            $relatedPosts = $this->applySubscriberPostVisibility(
                Post::whereHas('categories', function ($query) use ($categoryIDs) {
                    $query->whereIn('_id', $categoryIDs);
                })
                    ->statusActive()
                    ->excludeIDs($excludedPostIDs)
            )->get()->shuffle();

            $posts = array_merge($posts, $relatedPosts->all());
            $excludedPostIDs = array_merge($excludedPostIDs, $relatedPosts->pluck('id')->all());
        }

        $remainingPosts = $this->applySubscriberPostVisibility(
            Post::statusActive()->excludeIDs($excludedPostIDs)
        )->get();

        if ($remainingPosts->count() > 0) {
            $posts = array_merge($posts, $remainingPosts->shuffle()->all());
        }

        return collect($posts)
            ->pluck('id')
            ->chunk($itemsPerPage)
            ->map(fn ($chunk) => route('paraqon.posts.ids', ['ids' => $chunk->all()]))
            ->all();
    }

    /**
     * Mirror base private helper — parent method is not accessible to child.
     */
    protected function getPostsWithLikedAndCommentCountForParaqon(array $postIDs): Collection
    {
        $request = request()->merge(['ids' => $postIDs]);

        return parent::getPostsByIDs($request);
    }
}

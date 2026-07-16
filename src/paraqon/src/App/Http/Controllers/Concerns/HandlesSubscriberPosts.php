<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Concerns;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Paraqon-only: Vault subscriber gate for posts with requires_subscription.
 * Use via /api/customer/paraqon/posts/* — do not add to base PostController (tcg-bid shares base routes).
 */
trait HandlesSubscriberPosts
{
    protected function canViewSubscriberPost(): bool
    {
        if (!Auth::check()) {
            return false;
        }

        try {
            return $this->customerHasVaultSubscription($this->customer());
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function applySubscriberPostVisibility(Builder $query): Builder
    {
        if ($this->canViewSubscriberPost()) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->where('requires_subscription', '!=', true)
                ->orWhereNull('requires_subscription');
        });
    }

    /**
     * @param array<int, string> $postIDs
     * @return array<int, string>
     */
    protected function filterAllowedPostIds(array $postIDs): array
    {
        if ($this->canViewSubscriberPost() || count($postIDs) === 0) {
            return $postIDs;
        }

        return Post::whereIn('_id', $postIDs)
            ->where(function ($q) {
                $q->where('requires_subscription', '!=', true)
                    ->orWhereNull('requires_subscription');
            })
            ->pluck('id')
            ->all();
    }

    protected function assertCanViewPost(Post $post): void
    {
        if (!empty($post->requires_subscription) && !$this->canViewSubscriberPost()) {
            abort(403, 'This post is only available to Vault subscribers');
        }
    }
}

<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Concerns;

use App\Models\Configuration;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;

trait ReadsParaqonConfiguration
{
    protected function getLatestConfigurationBySlug(string $slug): ?Configuration
    {
        return Configuration::where('slug', $slug)->latest()->first();
    }

    protected function getCreditCardChargePercentage(): float
    {
        $config = $this->getLatestConfigurationBySlug('credit-card-charge-settings');
        if (is_null($config)) {
            return 3.5;
        }

        $percentage = $config->percentage ?? data_get($config->toArray(), 'percentage', 3.5);
        return (float) $percentage;
    }

    /**
     * Match a numeric value against inclusive min/max tiers.
     */
    protected function matchTier(array $tiers, float $value, string $minKey, string $maxKey): ?array
    {
        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                continue;
            }

            $min = isset($tier[$minKey]) ? (float) $tier[$minKey] : 0;
            $max = array_key_exists($maxKey, $tier) && !is_null($tier[$maxKey]) && $tier[$maxKey] !== ''
                ? (float) $tier[$maxKey]
                : null;

            if ($value < $min) {
                continue;
            }
            if (!is_null($max) && $value > $max) {
                continue;
            }

            return $tier;
        }

        return null;
    }

    protected function getBuyerCommissionDiscountPercent(float $orderTotalHammer): float
    {
        $config = $this->getLatestConfigurationBySlug('buyer-commission-discount-settings');
        if (is_null($config)) {
            return 0;
        }

        $tiers = $config->tiers ?? [];
        if (!is_array($tiers) || count($tiers) === 0) {
            return 0;
        }

        $tier = $this->matchTier($tiers, $orderTotalHammer, 'min_order_total', 'max_order_total');
        if (is_null($tier)) {
            return 0;
        }

        return (float) ($tier['discount_percent'] ?? 0);
    }

    protected function applyBuyerCommissionDiscount(float $commission, float $discountPercent): array
    {
        $commission0 = max(0, $commission);
        $discountPercent = max(0, min(100, $discountPercent));
        $commissionAfter = (float) floor($commission0 * (1 - $discountPercent / 100));
        $discountAmount = (float) max(0, floor($commission0) - $commissionAfter);

        return [
            'commission_before_discount' => (float) floor($commission0),
            'commission_discount_percent' => $discountPercent,
            'commission_discount_amount' => $discountAmount,
            'commission' => $commissionAfter,
        ];
    }

    /** Use the lot's Reserved Price for high-value deposit tier matching. */
    protected function getLotReservePrice(AuctionLot $lot): ?float
    {
        $reservePrice = $lot->reserve_price;
        if (is_null($reservePrice) || $reservePrice === '') {
            return null;
        }

        return (float) $reservePrice;
    }

    /**
     * Resolve high-value deposit amount from store.deposit_permissions (not global configurations).
     * Auction Deposit never applies credit-card fees.
     */
    protected function resolveHighValueDepositAmount($store, AuctionLot $lot): ?float
    {
        $permissionType = $lot->permission_type;
        if (is_null($permissionType) || $permissionType === '') {
            return null;
        }

        $depositPermissions = $store->deposit_permissions ?? [];
        if (!is_array($depositPermissions) || count($depositPermissions) === 0) {
            return null;
        }

        $matched = null;
        foreach ($depositPermissions as $permission) {
            if (!is_array($permission)) {
                continue;
            }
            if (($permission['permission_type'] ?? null) === $permissionType) {
                $matched = $permission;
                break;
            }
        }

        if (is_null($matched)) {
            return null;
        }

        $tiers = $matched['tiers'] ?? null;
        $fallbackAmount = isset($matched['amount']) ? (float) $matched['amount'] : null;

        if (!is_array($tiers) || count($tiers) === 0) {
            return $fallbackAmount;
        }

        $reservePrice = $this->getLotReservePrice($lot);
        if (is_null($reservePrice)) {
            return $fallbackAmount;
        }

        $tier = $this->matchTier($tiers, $reservePrice, 'min_value', 'max_value');
        if (is_null($tier)) {
            return $fallbackAmount;
        }

        return isset($tier['amount']) ? (float) $tier['amount'] : $fallbackAmount;
    }

    protected function customerHasVaultSubscription($customer): bool
    {
        if (is_null($customer)) {
            return false;
        }

        $groups = $customer->relationLoaded('groups')
            ? $customer->getRelation('groups')
            : $customer->groups()->statusActive()->get(['slug']);
        foreach ($groups as $group) {
            $slug = strtolower((string) ($group->slug ?? ''));
            if ($slug !== '' && str_contains($slug, 'the-vault')) {
                return true;
            }
        }

        return false;
    }
}

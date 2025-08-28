<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\ReplyStatus;
use App\Enums\ShipmentDeliveryStatus;

// Models
use App\Models\Order;
use Starsnet\Project\Rmhc\App\Models\BatchPayment;

class BatchPaymentController extends Controller
{
    public function getAllBatchPayments(Request $request): Collection
    {
        return BatchPayment::with(['customer.account.user'])->get();
    }

    public function getBatchPaymentDetails(Request $request): BatchPayment
    {
        /** @var ?BatchPayment $batchPayment */
        $batchPayment = BatchPayment::with(['customer.account.user'])
            ->find($request->route('id'));
        if (is_null($batchPayment)) abort(404, 'BatchPayment not found');

        $orderIDs = $batchPayment->order_ids;
        $batchPayment->orders = Order::whereIn('_id', $orderIDs)
            ->with(['store'])
            ->get();
        return $batchPayment;
    }

    public function updateBatchPayment(Request $request)
    {
        /** @var ?BatchPayment $batchPayment */
        $batchPayment = BatchPayment::find($request->route('id'));
        if (is_null($batchPayment)) abort(404, 'BatchPayment not found');

        $batchPayment->update($request->all());
        return ['message' => 'Updated BatchPayment successfully'];
    }

    public function approveBatchPayment(Request $request)
    {
        $approvalStatus = $request->approval_status ?? ReplyStatus::APPROVED->value;

        /** @var ?BatchPayment $batchPayment */
        $batchPayment = BatchPayment::find($request->route('id'));
        if (is_null($batchPayment)) abort(404, 'BatchPayment not found');
        if ($batchPayment->is_approved === true) abort(400, 'BatchPayment already approved, cannot be approved again');

        if ($approvalStatus == ReplyStatus::APPROVED->value) {
            $batchPayment->update([
                'is_approved' => true,
                'payment_received_at' => now()
            ]);

            $orders = Order::whereIn('_id', $batchPayment->order_ids)
                ->with(['store'])
                ->get();

            foreach ($orders as $order) {
                $checkout = $order->checkout()->latest()->first();

                $checkout->update([
                    'offline' => [
                        'image' => $batchPayment->payment_image,
                        'uploaded_at' => $batchPayment->payment_image_uploaded_at,
                        'api_response' => null
                    ]
                ]);

                $checkout->approval()->create([
                    'status' => ReplyStatus::APPROVED->value,
                    'reason' => "Batch Payment Approved by Admin",
                    'user_id' => $this->user()->id
                ]);

                // Update Order
                $order->update(['is_paid' => true]);

                if ($order->current_status !== ShipmentDeliveryStatus::PROCESSING->value) {
                    $order->updateStatus(ShipmentDeliveryStatus::PROCESSING->value);
                }
            }
        }

        if ($approvalStatus == ReplyStatus::REJECTED->value) {
            $batchPayment->update(['is_approved' => false]);

            $orders = Order::whereIn('_id', $batchPayment->order_ids)
                ->with(['store'])
                ->get();

            foreach ($orders as $order) {
                $checkout = $order->checkout()->latest()->first();

                $checkout->update([
                    'offline' => [
                        'image' => $batchPayment->payment_image,
                        'uploaded_at' => $batchPayment->payment_image_uploaded_at,
                        'api_response' => null
                    ]
                ]);

                $checkout->approval()->create([
                    'status' => ReplyStatus::REJECTED->value,
                    'reason' => "Batch Payment Rejected by Admin",
                    'user_id' => $this->user()->id
                ]);
            }
        }

        return ['message' => 'Updated BatchPayment successfully'];
    }
}

<?php

use Starsnet\Project\Paraqon\App\LiveSale\LiveSaleBook;
use Starsnet\Project\Paraqon\App\LiveSale\LiveSaleException;

require __DIR__ . '/../src/App/LiveSale/LiveSaleException.php';
require __DIR__ . '/../src/App/LiveSale/LiveSaleBook.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function expect(callable $fn, string $fragment): void
{
    try {
        $fn();
    } catch (LiveSaleException $e) {
        check(str_contains($e->getMessage(), $fragment), 'expected "' . $fragment . '" got "' . $e->getMessage() . '"');
        return;
    }
    fwrite(STDERR, 'expected exception containing "' . $fragment . '"' . PHP_EOL);
    exit(1);
}

function catalogue(): array
{
    return [
        [
            'lot_id' => 'lot-1',
            'lot_number' => 1,
            'product_id' => 'product-1',
            'title' => ['en' => 'Chair', 'zh' => '椅', 'cn' => '椅'],
            'starting_price' => 800,
            'reserve_price' => 1000,
            'bid_incremental_settings' => [
                'increments' => [
                    ['from' => 0, 'to' => 10000, 'increment' => 100],
                ],
                'estimate_price' => ['min' => 800, 'max' => 1200],
            ],
            'advance' => [
                'customer_id' => 'absentee-55',
                'amount' => 1500,
                'paddle_id' => 55,
            ],
            'created_at' => '2026-10-07T00:00:00Z',
        ],
        [
            'lot_id' => 'lot-2',
            'lot_number' => 2,
            'product_id' => 'product-2',
            'title' => ['en' => 'Table'],
            'starting_price' => 500,
            'reserve_price' => 0,
            'bid_incremental_settings' => [
                'increments' => [],
                'estimate_price' => ['min' => 500, 'max' => 800],
            ],
            'created_at' => '2026-10-07T00:00:00Z',
        ],
    ];
}

function book(): LiveSaleBook
{
    $book = LiveSaleBook::empty('store-1');
    $book->apply('start_sale', ['lots' => catalogue(), 'at' => '2026-10-07T01:00:00Z']);
    return $book;
}

function containsKey(array $value, string $key): bool
{
    if (array_key_exists($key, $value)) {
        return true;
    }
    foreach ($value as $item) {
        if (is_array($item) && containsKey($item, $key)) {
            return true;
        }
    }
    return false;
}

$preview = LiveSaleBook::empty('store-1');
$preview->loadPreview(catalogue());
check($preview->sequence() === 0, 'preview does not start the sale');
check($preview->publicPayload('t')['started'] === false, 'preview is not started');
check(containsKey($preview->publicPayload('t'), 'reserve_price') === false, 'preview hides the reserve');
check($preview->clerkPayload('t')['lots'][0]['reserve_price'] === 1000.0, 'clerk preview keeps the reserve');
check($preview->clerkPayload('t')['highest_advanced_bid']['bid'] === 1500.0, 'clerk preview keeps the absentee max');

$sale = book();
check($sale->sequence() === 1, 'start bumps the sequence');
check($sale->publicPayload('t')['current_lot_id'] === 'lot-1', 'start shows the first lot');
check($sale->publicPayload('t')['lots'][0]['status'] === 'ARCHIVED', 'upcoming lots stay archived');

expect(fn () => $sale->apply('open_lot', ['at' => 't']), 'Prepare the lot');
$sale->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
expect(fn () => $sale->apply('prepare_lot', ['lot_id' => 'lot-2', 'at' => 't']), 'Another lot');
$sale->apply('open_lot', ['at' => 't']);
check($sale->publicPayload('t')['asking_price'] === 800.0, 'opening ask is the starting price');

$sale->apply('submit_online_bid', [
    'amount' => 800,
    'customer_id' => 'online-201',
    'paddle_id' => 201,
    'at' => 't',
]);
check($sale->publicPayload('t')['has_pending'] === true, 'online bid waits');
check(containsKey($sale->publicPayload('t'), 'customer_id') === false, 'public payload hides customer ids');
check(containsKey($sale->publicPayload('t'), 'reserve_price') === false, 'public payload hides the reserve');
check($sale->clerkPayload('t')['pending'][0]['customer_id'] === 'online-201', 'clerk sees the pending bidder');

$sale->apply('accept_pending', [
    'pending_id' => $sale->clerkPayload('t')['pending'][0]['id'],
    'at' => 't2',
]);
check($sale->publicPayload('t')['asking_price'] === 900.0, 'table increment follows an accepted bid');
check($sale->publicPayload('t')['histories'][0]['paddle_label'] === 'Paddle #201', 'online bid keeps its paddle');
check($sale->publicPayload('t')['has_pending'] === false, 'other waiting bids are cleared');

$sale->apply('accept_bid', [
    'amount' => 900,
    'source' => 'floor',
    'paddle_id' => 12,
    'customer_id' => 'floor-12',
    'at' => 't3',
]);
$clerk = $sale->clerkPayload('t');
check($clerk['undo']['amount'] === 900.0, 'undo names the latest bid');
check($clerk['undo']['paddle_label'] === 'Floor bid', 'floor bid is labelled floor');
check($clerk['highest_advanced_bid']['bid'] === 1500.0, 'absentee max stays on the clerk book');
check($clerk['lots'][0]['reserve_met'] === false, '900 is under a 1000 reserve');

$advanceBefore = $sale->toArray()['lots'][0]['advance'];
$undoId = $clerk['undo']['bid_id'];
expect(fn () => $sale->apply('undo_latest_bid', ['bid_id' => 'someone-else', 'at' => 't']), 'no longer the latest');
$sale->apply('undo_latest_bid', ['bid_id' => $undoId, 'at' => 't4']);
$after = $sale->clerkPayload('t');
check($after['undo']['paddle_label'] === 'Paddle #201', 'the previous bid becomes the latest');
check($after['asking_price'] === 900.0, 'asking returns to the bid that was undone');
check($after['highest_advanced_bid']['bid'] === 1500.0, 'undo does not replay the absentee');
check($sale->toArray()['lots'][0]['advance'] === $advanceBefore, 'the absentee record is unchanged');

$sale->apply('undo_latest_bid', ['at' => 't5']);
check($sale->clerkPayload('t')['undo'] === null, 'undo stops when the book is empty');
check($sale->publicPayload('t')['asking_price'] === 800.0, 'asking returns to the opening price');
expect(fn () => $sale->apply('undo_latest_bid', ['at' => 't']), 'no bid to undo');

$sale->apply('accept_bid', [
    'amount' => 900,
    'source' => 'house',
    'at' => 't6',
]);
expect(fn () => $sale->apply('sell', ['at' => 't']), 'reserve');
$sale->apply('accept_bid', [
    'amount' => 1000,
    'source' => 'phone',
    'paddle_id' => 33,
    'customer_id' => 'phone-33',
    'at' => 't7',
]);
$sale->apply('warn', ['action' => 'FAIR_WARNING', 'at' => 't8']);
check($sale->publicPayload('t')['warning'] === 'FAIR_WARNING', 'warning is public');
$sale->apply('sell', ['at' => 't9']);
expect(fn () => $sale->apply('undo_latest_bid', ['at' => 't']), 'Open the lot');
check($sale->publicPayload('t')['lots'][0]['is_disabled'] === true, 'sold lot is disabled');
check($sale->publicPayload('t')['lots'][0]['is_closed'] === false, 'sold is not a pass');
check($sale->publicPayload('t')['lots'][0]['current_bid'] === 1000.0, 'hammer is the current bid');

$sale->apply('reopen', ['lot_id' => 'lot-1', 'at' => 't10']);
$sale->apply('undo_latest_bid', ['at' => 't11']);
check($sale->clerkPayload('t')['undo']['paddle_label'] === 'Floor bid', 'reopen lets the clerk undo the new latest bid');

expect(fn () => $sale->apply('end_sale', ['at' => 't']), 'on the block');
$sale->apply('pass', ['at' => 't12']);
check($sale->publicPayload('t')['lots'][0]['is_closed'] === true, 'pass closes the lot');
$sale->apply('prepare_lot', ['lot_id' => 'lot-2', 'at' => 't13']);
$sale->apply('open_lot', ['at' => 't14']);
$sale->apply('set_increment', ['increment' => 50, 'at' => 't15']);
$sale->apply('accept_bid', [
    'amount' => 500,
    'source' => 'floor',
    'paddle_id' => 2,
    'customer_id' => 'floor-2',
    'at' => 't16',
]);
check($sale->publicPayload('t')['asking_price'] === 550.0, 'custom increment is used for the next ask');
$sale->apply('pass', ['at' => 't17']);
$sale->apply('end_sale', ['at' => 't18']);
expect(fn () => $sale->apply('open_lot', ['at' => 't']), 'ended');

$restored = LiveSaleBook::fromArray($sale->toArray());
check($restored->sequence() === $sale->sequence(), 'the book round-trips');
check($restored->status() === 'ended', 'ended status round-trips');

echo "ok\n";

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
check($sale->publicPayload('t')['histories'] === [], 'a lone absentee does not bid on open');

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
check($sale->publicPayload('t')['asking_price'] === 1000.0, 'the absentee answers one increment above the online bid');
check($sale->publicPayload('t')['histories'][0]['paddle_label'] === 'Paddle #201', 'online bid keeps its paddle');
check($sale->publicPayload('t')['histories'][0]['current_bid'] === 800.0, 'the online bid stays on the ladder');
check($sale->publicPayload('t')['histories'][1]['paddle_label'] === 'Paddle #55', 'the room sees the absentee paddle');
check($sale->publicPayload('t')['histories'][1]['current_bid'] === 900.0, 'the book bids one increment');
check($sale->publicPayload('t')['has_pending'] === false, 'other waiting bids are cleared');
check(containsKey($sale->publicPayload('t'), 'customer_id') === false, 'the book bid does not publish a customer id');
check(containsKey($sale->publicPayload('t'), 'advance') === false, 'the absentee max stays off the public book');

$clerk = $sale->clerkPayload('t');
check($clerk['undo']['amount'] === 900.0, 'undo names the book bid');
check($clerk['undo']['paddle_label'] === 'Paddle #55', 'undo names the absentee paddle');
check($clerk['highest_advanced_bid']['bid'] === 1500.0, 'absentee max stays on the clerk book');

$advanceBefore = $sale->toArray()['lots'][0]['advance'];
$undoId = $clerk['undo']['bid_id'];
expect(fn () => $sale->apply('undo_latest_bid', ['bid_id' => 'someone-else', 'at' => 't']), 'no longer the latest');
$sale->apply('undo_latest_bid', ['bid_id' => $undoId, 'at' => 't4']);
$after = $sale->clerkPayload('t');
check($after['undo']['paddle_label'] === 'Paddle #201', 'the previous bid becomes the latest');
check($after['asking_price'] === 900.0, 'asking returns to the bid that was undone');
check(count($after['histories']) === 1, 'the book bid leaves the ladder');
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
check($sale->clerkPayload('t')['undo']['paddle_label'] === 'Paddle #55', 'a house bid under the max draws the absentee');
check($sale->clerkPayload('t')['undo']['amount'] === 1000.0, 'the book bids the next increment');
check($sale->clerkPayload('t')['lots'][0]['reserve_met'] === true, 'the book bid meets the reserve');
$sale->apply('undo_latest_bid', ['at' => 't6b']);
check($sale->clerkPayload('t')['undo']['paddle_label'] === 'Floor bid', 'undo of the book bid leaves the house bid');
check($sale->clerkPayload('t')['lots'][0]['reserve_met'] === false, '900 is under a 1000 reserve');
check($sale->toArray()['lots'][0]['advance'] === $advanceBefore, 'undo still does not replay the absentee');
expect(fn () => $sale->apply('sell', ['at' => 't']), 'reserve');
$sale->apply('accept_bid', [
    'amount' => 1000,
    'source' => 'phone',
    'paddle_id' => 33,
    'customer_id' => 'phone-33',
    'at' => 't7',
]);
check($sale->publicPayload('t')['histories'][count($sale->publicPayload('t')['histories']) - 1]['paddle_label'] === 'Paddle #55', 'the phone bid is answered by the absentee');
$sale->apply('warn', ['action' => 'FAIR_WARNING', 'at' => 't8']);
check($sale->publicPayload('t')['warning'] === 'FAIR_WARNING', 'warning is public');
$sale->apply('sell', ['at' => 't9']);
expect(fn () => $sale->apply('undo_latest_bid', ['at' => 't']), 'Open the lot');
check($sale->publicPayload('t')['lots'][0]['is_disabled'] === true, 'sold lot is disabled');
check($sale->publicPayload('t')['lots'][0]['is_closed'] === false, 'sold is not a pass');
check($sale->publicPayload('t')['lots'][0]['current_bid'] === 1100.0, 'hammer is the absentee answer');

$sale->apply('reopen', ['lot_id' => 'lot-1', 'at' => 't10']);
$sale->apply('undo_latest_bid', ['at' => 't11']);
check($sale->clerkPayload('t')['undo']['paddle_label'] === 'Phone #33', 'reopen lets the clerk undo the new latest bid');
check(count($sale->publicPayload('t')['histories']) === 2, 'undo removes only the book bid');

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

$duelLots = catalogue();
$duelLots[0]['advances'] = [
    [
        'customer_id' => 'early',
        'amount' => 1000,
        'paddle_id' => 1,
        'placed_at' => '2026-10-01T00:00:00Z',
    ],
    [
        'customer_id' => 'late',
        'amount' => 1000,
        'paddle_id' => 2,
        'placed_at' => '2026-10-02T00:00:00Z',
    ],
];
unset($duelLots[0]['advance']);
$duel = LiveSaleBook::empty('store-duel');
$duel->apply('start_sale', ['lots' => $duelLots, 'at' => 't']);
$duel->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$duel->apply('open_lot', ['at' => 't']);
$duelLadder = array_map(
    fn ($row) => [$row['current_bid'], $row['paddle_label']],
    $duel->publicPayload('t')['histories']
);
check($duelLadder === [
    [800.0, 'Paddle #1'],
    [900.0, 'Paddle #2'],
    [1000.0, 'Paddle #1'],
], 'tied absentees open against each other and the earlier bid takes the maximum');
check($duel->publicPayload('t')['asking_price'] === 1100.0, 'asking moves past the tied maximum');
check($duel->clerkPayload('t')['highest_advanced_bid']['paddle_id'] === 1, 'the earlier maximum stays the top absentee');
$duel->apply('undo_latest_bid', ['at' => 't']);
check($duel->publicPayload('t')['histories'][count($duel->publicPayload('t')['histories']) - 1]['paddle_label'] === 'Paddle #2', 'undo of the opening duel does not bid the maximum again');

$raceLots = catalogue();
$raceLots[0]['advances'] = [
    [
        'customer_id' => 'high',
        'amount' => 1500,
        'paddle_id' => 11,
        'placed_at' => '2026-10-01T00:00:00Z',
    ],
    [
        'customer_id' => 'low',
        'amount' => 1200,
        'paddle_id' => 12,
        'placed_at' => '2026-10-03T00:00:00Z',
    ],
];
unset($raceLots[0]['advance']);
$race = LiveSaleBook::empty('store-race');
$race->apply('start_sale', ['lots' => $raceLots, 'at' => 't']);
$race->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$race->apply('open_lot', ['at' => 't']);
$raceLadder = array_map(
    fn ($row) => [$row['current_bid'], $row['paddle_label']],
    $race->publicPayload('t')['histories']
);
check($raceLadder === [
    [800.0, 'Paddle #11'],
    [900.0, 'Paddle #12'],
    [1000.0, 'Paddle #11'],
    [1100.0, 'Paddle #12'],
    [1200.0, 'Paddle #11'],
], 'unequal absentees stop one step above the lower maximum');
check($race->publicPayload('t')['asking_price'] === 1300.0, 'the higher absentee does not bid against themselves');

$youLots = catalogue();
unset($youLots[0]['advance']);
$youLots[0]['advances'] = [];
$youLots[0]['reserve_price'] = 0;
$you = LiveSaleBook::empty('store-you');
$you->apply('start_sale', ['lots' => $youLots, 'at' => 't0']);
$you->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't0']);
$you->apply('open_lot', ['at' => 't0', 'advances' => []]);
check($you->publicPayload('t')['histories'] === [], 'opening with no absentees leaves the ladder empty');
$you->apply('submit_online_bid', [
    'amount' => 800,
    'customer_id' => 'online-a',
    'paddle_id' => 201,
    'at' => 't1',
]);
$waiting = $you->viewer('online-a');
check($waiting['result'] === 'pending', 'the bidder is told the bid is waiting');
check($waiting['pending']['amount'] === 800.0, 'the waiting bid keeps its amount');
check($you->viewer('someone-else')['result'] === null, 'another customer has no result');
check(containsKey($you->publicPayload('t'), 'you') === false, 'the public room does not carry a personal result');
$you->apply('accept_pending', [
    'pending_id' => $you->clerkPayload('t')['pending'][0]['id'],
    'at' => 't2',
]);
check($you->viewer('online-a')['result'] === 'with_you', 'an accepted bid is with that customer');
$you->apply('accept_bid', [
    'amount' => 900,
    'source' => 'floor',
    'paddle_id' => 12,
    'customer_id' => 'floor-12',
    'at' => 't3',
]);
check($you->viewer('online-a')['result'] === 'outbid', 'the earlier bidder has been outbid');
check($you->viewer('floor-12')['result'] === 'with_you', 'the floor bid is with that paddle');
$you->apply('undo_latest_bid', ['at' => 't4']);
check($you->viewer('floor-12')['result'] === 'removed', 'the undone bidder is told the bid was removed');
check($you->viewer('online-a')['result'] === 'with_you', 'undo puts the previous bidder back with the bid');
check(containsKey($you->publicPayload('t'), 'customer_id') === false, 'a withdrawn bid stays off the public room');
$you->apply('accept_bid', [
    'amount' => 900,
    'source' => 'floor',
    'paddle_id' => 12,
    'customer_id' => 'floor-12',
    'at' => 't5',
]);
$you->apply('sell', ['at' => 't6']);
check($you->viewer('floor-12')['result'] === 'won', 'the hammer winner is told they won');
check($you->viewer('online-a')['result'] === null, 'a losing bidder is not told they won');

$refreshLots = catalogue();
$refreshLots[0]['advances'] = [[
    'customer_id' => 'old',
    'amount' => 900,
    'paddle_id' => 9,
    'placed_at' => '2026-10-01T00:00:00Z',
]];
unset($refreshLots[0]['advance']);
$refresh = LiveSaleBook::empty('store-refresh');
$refresh->apply('start_sale', ['lots' => $refreshLots, 'at' => 't']);
$refresh->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$refresh->apply('open_lot', [
    'at' => 't',
    'advances' => [
        [
            'customer_id' => 'early',
            'amount' => 1000,
            'paddle_id' => 1,
            'placed_at' => '2026-10-01T00:00:00Z',
        ],
        [
            'customer_id' => 'late',
            'amount' => 1000,
            'paddle_id' => 2,
            'placed_at' => '2026-10-02T00:00:00Z',
        ],
    ],
]);
check($refresh->clerkPayload('t')['highest_advanced_bid']['paddle_id'] === 1, 'open replaces the absentee list from before the lot opened');
check(count($refresh->publicPayload('t')['histories']) === 3, 'the replacement absentees compete when the lot opens');

echo "ok\n";

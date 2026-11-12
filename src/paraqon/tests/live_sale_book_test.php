<?php

use Starsnet\Project\Paraqon\App\LiveSale\CurrentBidCalculation;
use Starsnet\Project\Paraqon\App\LiveSale\LiveSaleBook;
use Starsnet\Project\Paraqon\App\LiveSale\LiveSaleException;

require __DIR__ . '/../src/App/LiveSale/LiveSaleException.php';
require __DIR__ . '/../src/App/LiveSale/CurrentBidCalculation.php';
require __DIR__ . '/../src/App/LiveSale/LiveSaleBook.php';
require __DIR__ . '/../src/App/LiveSale/LiveSaleService.php';

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

$tieRules = [['from' => 0, 'to' => 2147483647, 'increment' => 10000]];
$tieBids = [
    ['customer_id' => '103', 'bid' => 150000, 'type' => 'DIRECT', 'created_at' => '2026-10-09T10:31:00Z'],
    ['customer_id' => '102', 'bid' => 120000, 'type' => 'ADVANCED', 'created_at' => '2026-10-07T04:00:02Z'],
    ['customer_id' => '101', 'bid' => 150000, 'type' => 'ADVANCED', 'created_at' => '2026-10-07T03:59:50Z'],
];
$tieHistory = ['winning_bid_customer_id' => '101', 'current_bid' => 140000];
check(
    CurrentBidCalculation::price($tieBids, 80000, 100000, $tieRules, '103', 150000, 'DIRECT', $tieHistory) == 150000,
    'a direct bid that ties an earlier advance stays at that amount'
);
check(CurrentBidCalculation::winnerId($tieBids) === '101', 'the earlier of the equal highest bids wins');
$overBids = $tieBids;
$overBids[0]['bid'] = 160000;
check(
    CurrentBidCalculation::price($overBids, 80000, 100000, $tieRules, '103', 160000, 'DIRECT', $tieHistory) == 160000,
    'a direct bid above every maximum is the price'
);
check(CurrentBidCalculation::winnerId($overBids) === '103', 'the higher direct bidder wins');
$stepRules = [['from' => 0, 'to' => 10000, 'increment' => 100]];
check(CurrentBidCalculation::price([
    ['customer_id' => 'high', 'bid' => 1500, 'type' => 'ADVANCED', 'created_at' => '2026-10-01'],
    ['customer_id' => 'low', 'bid' => 1200, 'type' => 'ADVANCED', 'created_at' => '2026-10-03'],
], 800, 1000, $stepRules, 'high', 1500, 'ADVANCED', null) == 1300, 'two maxima price one increment above the second');
check(CurrentBidCalculation::price([
    ['customer_id' => 'only', 'bid' => 1500, 'type' => 'ADVANCED', 'created_at' => 't'],
], 800, 1000, $stepRules, 'only', 1500, 'ADVANCED', null) == 1000, 'one maximum that meets the reserve prices the reserve');
check(CurrentBidCalculation::price([
    ['customer_id' => 'only', 'bid' => 900, 'type' => 'ADVANCED', 'created_at' => 't'],
], 800, 1000, $stepRules, 'only', 900, 'ADVANCED', null) == 800, 'one maximum under the reserve prices the opening');

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
check($sale->publicPayload('t')['has_pending'] === false, 'an online bid is accepted');
check($sale->clerkPayload('t')['pending'] === [], 'an accepted online bid is not left waiting');
check($sale->viewer('online-201')['result'] === 'with_you', 'an advance is not bid over the online bidder');
check(containsKey($sale->publicPayload('t'), 'customer_id') === false, 'public payload hides customer ids');
check(containsKey($sale->publicPayload('t'), 'reserve_price') === false, 'public payload hides the reserve');
check(containsKey($sale->publicPayload('t'), 'advances') === false, 'the book stays off the public room');
check($sale->publicPayload('t')['asking_price'] === 900.0, 'the next ask is the lot increment');
check($sale->publicPayload('t')['histories'][0]['paddle_label'] === 'Paddle #201', 'online bid keeps its paddle');
check($sale->publicPayload('t')['histories'][0]['current_bid'] === 800.0, 'the online bid stays on the ladder');
check(count($sale->publicPayload('t')['histories']) === 1, 'an advance is not bid for the customer');
check(containsKey($sale->publicPayload('t'), 'advance') === false, 'the absentee max stays off the public book');

$clerk = $sale->clerkPayload('t');
check($clerk['undo']['amount'] === 800.0, 'undo names the online bid');
check($clerk['undo']['paddle_label'] === 'Paddle #201', 'undo names the online paddle');
check($clerk['highest_advanced_bid']['bid'] === 1500.0, 'absentee max stays on the clerk book');
check($clerk['advances'][0]['paddle_id'] === 55, 'the book lists the advance for the auctioneer');
check($clerk['advances'][0]['amount'] === 1500.0, 'the book lists the advance amount');

$advanceBefore = $sale->toArray()['lots'][0]['advance'];
$undoId = $clerk['undo']['bid_id'];
expect(fn () => $sale->apply('undo_latest_bid', ['bid_id' => 'someone-else', 'at' => 't']), 'no longer the latest');
$sale->apply('undo_latest_bid', ['bid_id' => $undoId, 'at' => 't4']);
$after = $sale->clerkPayload('t');
check($after['undo'] === null, 'undo of the only bid leaves nothing to undo');
check($after['asking_price'] === 800.0, 'asking returns to the opening price');
check(count($after['histories']) === 0, 'the online bid leaves the ladder');
check($after['highest_advanced_bid']['bid'] === 1500.0, 'undo does not replay the absentee');
check($sale->toArray()['lots'][0]['advance'] === $advanceBefore, 'the absentee record is unchanged');

expect(fn () => $sale->apply('undo_latest_bid', ['at' => 't']), 'no bid to undo');

$sale->apply('accept_bid', [
    'amount' => 900,
    'source' => 'house',
    'at' => 't6',
]);
check($sale->clerkPayload('t')['undo']['paddle_label'] === 'Absentee bid', 'an absentee bid is not covered by the book');
check($sale->clerkPayload('t')['undo']['amount'] === 900.0, 'the absentee bid stays at the spoken price');
check($sale->publicPayload('t')['asking_price'] === 1000.0, 'the next ask is the lot increment');
check($sale->clerkPayload('t')['lots'][0]['reserve_met'] === false, '900 is under a 1000 reserve');
check($sale->toArray()['lots'][0]['advance'] === $advanceBefore, 'an absentee bid does not spend the advance');
expect(fn () => $sale->apply('sell', ['at' => 't']), 'paddle number');
$sale->apply('sell', ['paddle_id' => 33, 'customer_id' => 'phone-33', 'at' => 't']);
check($sale->publicPayload('t')['lots'][0]['live_state'] === 'sold', 'a lot under the reserve can be sold');
check($sale->publicPayload('t')['lots'][0]['current_bid'] === 900.0, 'the hammer can be under the reserve');
$sale->apply('reopen', ['lot_id' => 'lot-1', 'at' => 't-under']);
$sale->apply('accept_bid', [
    'amount' => 1200,
    'source' => 'house',
    'at' => 't6b',
]);
check($sale->clerkPayload('t')['lots'][0]['reserve_met'] === true, 'an absentee bid may go above the reserve');
$sale->apply('undo_latest_bid', ['at' => 't6c']);
$sale->apply('accept_bid', [
    'amount' => 1000,
    'source' => 'phone',
    'at' => 't7',
]);
check($sale->publicPayload('t')['histories'][count($sale->publicPayload('t')['histories']) - 1]['paddle_label'] === 'Phone', 'a phone bid does not need a paddle');
expect(fn () => $sale->apply('sell', ['at' => 't']), 'paddle number');
$sale->apply('warn', ['action' => 'FAIR_WARNING', 'at' => 't8']);
check($sale->publicPayload('t')['warning'] === 'FAIR_WARNING', 'warning is public');
expect(fn () => $sale->apply('warn', ['action' => 'LAST_WARNING', 'at' => 't8b']), 'Unknown warning');
$sale->apply('sell', ['paddle_id' => 33, 'customer_id' => 'phone-33', 'at' => 't9']);
expect(fn () => $sale->apply('undo_latest_bid', ['at' => 't']), 'Open the lot');
check($sale->publicPayload('t')['lots'][0]['is_disabled'] === true, 'sold lot is disabled');
check($sale->publicPayload('t')['lots'][0]['is_closed'] === false, 'sold is not a pass');
check($sale->publicPayload('t')['lots'][0]['current_bid'] === 1000.0, 'hammer is the phone bid');
check($sale->publicPayload('t')['lots'][0]['hammer_paddle_label'] === 'Paddle #33', 'the paddle is recorded when the lot is sold');

$sale->apply('reopen', ['lot_id' => 'lot-1', 'at' => 't10']);
check($sale->publicPayload('t')['histories'] === [], 'reopen clears the bid history');
check($sale->publicPayload('t')['asking_price'] === 800.0, 'reopen returns to the opening price');
check($sale->publicPayload('t')['lots'][0]['current_bid'] == 0, 'reopen clears the hammer from the ladder');
check($sale->publicPayload('t')['lots'][0]['live_state'] === 'open', 'reopen puts the lot back on the block');
check($sale->clerkPayload('t')['undo'] === null, 'reopen leaves nothing to undo');
check($sale->toArray()['lots'][0]['advance'] === $advanceBefore, 'reopen keeps the absentee maximum');
expect(fn () => $sale->apply('undo_latest_bid', ['at' => 't11']), 'no bid to undo');

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
check($duelLadder === [], 'opening does not bid the advances against each other');
check($duel->publicPayload('t')['asking_price'] === 800.0, 'opening stays at the starting price');
check($duel->clerkPayload('t')['highest_advanced_bid']['paddle_id'] === 1, 'the earlier maximum stays the top absentee');
check(count($duel->clerkPayload('t')['advances']) === 2, 'the book lists every advance');
$duel->apply('take_book', ['at' => 't']);
check($duel->publicPayload('t')['histories'][0]['paddle_label'] === 'Paddle #1', 'the clerk takes one advance from the book');
check(count($duel->publicPayload('t')['histories']) === 1, 'taking the book places one bid');
$duel->apply('undo_latest_bid', ['at' => 't']);
check($duel->publicPayload('t')['histories'] === [], 'undo of a taken advance does not bid the book again');

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
check($raceLadder === [], 'unequal advances are not bid when the lot opens');
check($race->publicPayload('t')['asking_price'] === 800.0, 'the higher advance does not bid against the lower one');
check($race->clerkPayload('t')['advances'][0]['paddle_id'] === 11, 'the book lists the higher advance first');

$matchLots = catalogue();
unset($matchLots[0]['advance']);
$matchLots[0]['advances'] = [[
    'customer_id' => 'early',
    'amount' => 1000,
    'paddle_id' => 101,
    'placed_at' => '2026-10-01T00:00:00Z',
]];
$match = LiveSaleBook::empty('store-match');
$match->apply('start_sale', ['lots' => $matchLots, 'at' => 't']);
$match->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$match->apply('open_lot', ['at' => 't']);
$match->apply('accept_bid', [
    'amount' => 800,
    'source' => 'floor',
    'paddle_id' => 103,
    'customer_id' => 'floor-103',
    'at' => 't1',
]);
check($match->publicPayload('t')['histories'][count($match->publicPayload('t')['histories']) - 1]['paddle_label'] === 'Floor bid', 'a floor bid is not answered by an advance');
check($match->publicPayload('t')['asking_price'] === 900.0, 'the next ask is the lot increment');
$match->apply('submit_online_bid', [
    'amount' => 900,
    'customer_id' => 'online-103',
    'paddle_id' => 103,
    'at' => 't2',
]);
$matchLadder = array_map(
    fn ($row) => [$row['current_bid'], $row['paddle_label']],
    $match->publicPayload('t')['histories']
);
check($matchLadder === [
    [800.0, 'Floor bid'],
    [900.0, 'Paddle #103'],
], 'an online bid is not taken over by an advance');
check($match->viewer('early')['result'] === null, 'an unused advance is not the leader');
check($match->viewer('online-103')['result'] === 'with_you', 'the online bid stays with that paddle');
$match->apply('accept_bid', [
    'amount' => 2050,
    'source' => 'floor',
    'at' => 't4',
]);
check($match->publicPayload('t')['histories'][count($match->publicPayload('t')['histories']) - 1]['current_bid'] === 2050.0, 'an off-ladder floor bid is taken at the spoken price');
check($match->publicPayload('t')['asking_price'] === 2100.0, 'an off-ladder bid returns to the next increment');
check($match->publicPayload('t')['histories'][count($match->publicPayload('t')['histories']) - 1]['paddle_label'] === 'Floor bid', 'a floor bid above the book does not need a paddle');

$autoLots = catalogue();
unset($autoLots[0]['advance']);
$autoLots[0]['advances'] = [];
$autoLots[0]['reserve_price'] = 0;
$auto = LiveSaleBook::empty('store-auto');
$auto->apply('start_sale', ['lots' => $autoLots, 'at' => 't0']);
$auto->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't0']);
$auto->apply('open_lot', ['at' => 't0', 'advances' => []]);
$auto->apply('submit_online_bid', [
    'amount' => 800,
    'customer_id' => 'online-a',
    'paddle_id' => 201,
    'at' => 't1',
]);
check($auto->publicPayload('t')['has_pending'] === false, 'auto accept does not leave the bid waiting');
check($auto->clerkPayload('t')['pending'] === [], 'auto accept clears the waiting list');
check($auto->viewer('online-a')['result'] === 'with_you', 'an auto-accepted bid is with that customer');
check($auto->publicPayload('t')['histories'][0]['current_bid'] === 800.0, 'auto accept places the online bid');
check($auto->publicPayload('t')['histories'][0]['paddle_label'] === 'Paddle #201', 'auto accept keeps the paddle');
check($auto->publicPayload('t')['asking_price'] === 900.0, 'auto accept raises the asking price by the lot increment');
$autoAgain = LiveSaleBook::fromArray($auto->toArray());
$autoAgain->apply('submit_online_bid', [
    'amount' => 900,
    'customer_id' => 'online-b',
    'paddle_id' => 202,
    'at' => 't2',
]);
check($autoAgain->viewer('online-b')['result'] === 'with_you', 'a reloaded book still auto-accepts');
check($autoAgain->viewer('online-a')['result'] === 'outbid', 'the earlier auto-accepted bidder is outbid');
$autoAgain->setAutoAcceptOnlineBids(false);
$autoAgain->apply('submit_online_bid', [
    'amount' => 1000,
    'customer_id' => 'online-c',
    'paddle_id' => 203,
    'at' => 't3',
]);
check($autoAgain->viewer('online-c')['result'] === 'pending', 'turning auto accept off leaves the bid waiting');
check($autoAgain->clerkPayload('t')['pending'][0]['customer_id'] === 'online-c', 'the clerk still sees a waiting bid');
check(count($autoAgain->publicPayload('t')['histories']) === 2, 'a waiting bid is not on the ladder');

$youLots = catalogue();
unset($youLots[0]['advance']);
$youLots[0]['advances'] = [];
$youLots[0]['reserve_price'] = 0;
$you = LiveSaleBook::empty('store-you');
$you->setAutoAcceptOnlineBids(false);
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
$you->apply('prepare_lot', ['lot_id' => 'lot-2', 'at' => 't7']);
$you->apply('open_lot', ['at' => 't7', 'advances' => []]);
$marked = $you->forViewer($you->publicPayload('t'), 'floor-12');
$lot1 = null;
foreach ($marked['lots'] as $row) {
    if ($row['_id'] === 'lot-1') {
        $lot1 = $row;
    }
}
check(($lot1['winning_bid_customer_id'] ?? null) === 'floor-12', 'a sold lot keeps the winner on that customer book after the sale moves on');
check($lot1['status'] === 'ACTIVE' && $lot1['is_disabled'] === true && $lot1['is_closed'] === false, 'a sold lot stays in the past-lot shape');
check(containsKey($you->publicPayload('t'), 'winning_bid_customer_id') === false, 'the public room does not name the winner');
check(containsKey($you->forViewer($you->publicPayload('t'), 'online-a'), 'winning_bid_customer_id') === false, 'another customer is not told they won that lot');

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
check($refresh->publicPayload('t')['histories'] === [], 'opening does not bid the replacement advances');
check(count($refresh->clerkPayload('t')['advances']) === 2, 'the book lists the advances copied at open');

$preferLots = catalogue();
$preferLots[0]['reserve_price'] = 0;
unset($preferLots[1]);
$prefer = LiveSaleBook::empty('store-prefer');
$prefer->setAutoAcceptOnlineBids(false);
$prefer->apply('start_sale', ['lots' => array_values($preferLots), 'at' => 't']);
$prefer->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$prefer->apply('open_lot', ['at' => 't']);
check($prefer->publicPayload('t')['asking_price'] === 800.0, 'a lone absentee still waits at the opening ask');
check($prefer->publicPayload('t')['histories'] === [], 'the book does not bid until the clerk takes it');
check($prefer->clerkPayload('t')['book_can_take'] === true, 'the clerk can take the book at the opening ask');
$prefer->apply('submit_online_bid', [
    'amount' => 800,
    'customer_id' => 'online-201',
    'paddle_id' => 201,
    'at' => 't',
]);
$taken = $prefer->apply('take_book', ['at' => 't']);
check($taken['amount'] === 800.0, 'the book takes the asking price');
check($taken['preferred_over'] === 'online', 'the book is preferred over the waiting online bid');
$preferPublic = $prefer->publicPayload('t');
check(count($preferPublic['histories']) === 1, 'taking the book writes one stack row');
check($preferPublic['histories'][0]['current_bid'] === 800.0, 'the book bid is the asking price');
check($preferPublic['histories'][0]['paddle_label'] === 'Paddle #55', 'the room sees the absentee paddle');
check($preferPublic['histories'][0]['source'] === 'advance', 'the stack row is the absentee');
check($preferPublic['asking_price'] === 900.0, 'asking moves by the lot increment and the absentee does not bid again');
check($preferPublic['has_pending'] === false, 'the waiting online bid is dropped');
check($prefer->clerkPayload('t')['book_can_take'] === false, 'the leader cannot take the book against themselves');
check(containsKey($preferPublic, 'customer_id') === false, 'a book take does not publish a customer id');
check(containsKey($preferPublic, 'settlement') === false, 'settlement stays off the public room');
check(containsKey($preferPublic, 'book_can_take') === false, 'the public room does not offer the book');
check(containsKey($preferPublic, 'you') === false, 'a book take does not publish a personal result');
check(containsKey($preferPublic, 'hammer_customer_id') === false, 'a book take does not publish the hammer customer');
check(array_key_exists('settlement', $prefer->clerkPayload('t')), 'the clerk book carries settlement');

$jumpedLots = catalogue();
$jumpedLots[0]['reserve_price'] = 0;
unset($jumpedLots[1]);
$jumped = LiveSaleBook::empty('store-jumped');
$jumped->setAutoAcceptOnlineBids(false);
$jumped->apply('start_sale', ['lots' => array_values($jumpedLots), 'at' => 't']);
$jumped->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$jumped->apply('open_lot', ['at' => 't']);
$jumped->apply('set_asking_price', ['amount' => 1400, 'at' => 't']);
$jumped->apply('submit_online_bid', [
    'amount' => 1400,
    'customer_id' => 'online-201',
    'paddle_id' => 201,
    'at' => 't',
]);
$jumped->apply('take_book', ['at' => 't']);
check(count($jumped->publicPayload('t')['histories']) === 1, 'a jumped asking price is one book bid');
check($jumped->publicPayload('t')['histories'][0]['current_bid'] === 1400.0, 'the book takes the jumped asking price');
check($jumped->publicPayload('t')['asking_price'] === 1500.0, 'the next ask is the next ladder price and the absentee does not walk further');
check($jumped->publicPayload('t')['has_pending'] === false, 'the online bid at the jumped price is dropped');

$coverLots = catalogue();
$coverLots[0]['advance']['amount'] = 900;
$coverLots[0]['reserve_price'] = 0;
unset($coverLots[1]);
$cover = LiveSaleBook::empty('store-cover');
$cover->apply('start_sale', ['lots' => array_values($coverLots), 'at' => 't']);
$cover->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$cover->apply('open_lot', ['at' => 't']);
$cover->apply('accept_bid', [
    'amount' => 800,
    'source' => 'floor',
    'paddle_id' => 2,
    'customer_id' => 'floor-2',
    'at' => 't',
]);
$coverLadder = array_map(
    fn ($row) => [$row['current_bid'], $row['source']],
    $cover->publicPayload('t')['histories']
);
check($coverLadder === [
    [800.0, 'floor'],
], 'an advance inside the next rung is not bid for the customer');
check($cover->publicPayload('t')['asking_price'] === 900.0, 'the next ask is the advance maximum, and the book is not taken');
check($cover->clerkPayload('t')['book_can_take'] === true, 'the clerk can still choose to take that advance');
check($cover->viewer('absentee-55')['result'] === null, 'the unused advance is not the leader');
check($cover->viewer('floor-2')['result'] === 'with_you', 'the floor bid stays in the lead');

$sameLots = catalogue();
unset($sameLots[0]['advance']);
$sameLots[0]['advances'] = [];
$sameLots[0]['reserve_price'] = 0;
unset($sameLots[1]);
$same = LiveSaleBook::empty('store-same');
$same->setAutoAcceptOnlineBids(false);
$same->apply('start_sale', ['lots' => array_values($sameLots), 'at' => 't']);
$same->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$same->apply('open_lot', ['at' => 't', 'advances' => []]);
$same->apply('submit_online_bid', [
    'amount' => 800,
    'customer_id' => 'online-201',
    'paddle_id' => 201,
    'at' => 't',
]);
$same->apply('accept_bid', [
    'amount' => 800,
    'source' => 'floor',
    'paddle_id' => 4,
    'customer_id' => 'floor-4',
    'at' => 't',
]);
check(count($same->publicPayload('t')['histories']) === 1, 'a floor bid at the asking price is not a second bid');
check($same->publicPayload('t')['histories'][0]['source'] === 'floor', 'the floor bid is the stack row');
check($same->publicPayload('t')['histories'][0]['current_bid'] === 800.0, 'the floor bid takes the asking price');
check($same->publicPayload('t')['has_pending'] === false, 'the pending online bid is cleared');

$shortLots = catalogue();
$shortLots[0]['advance']['amount'] = 700;
unset($shortLots[1]);
$short = LiveSaleBook::empty('store-short');
$short->apply('start_sale', ['lots' => array_values($shortLots), 'at' => 't']);
$short->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$short->apply('open_lot', ['at' => 't']);
expect(fn () => $short->apply('take_book', ['at' => 't']), 'No absentee bid can take the asking price');
check($short->clerkPayload('t')['book_can_take'] === false, 'a short absentee cannot take the asking price');

$phoneLots = catalogue();
unset($phoneLots[0]['advance']);
$phoneLots[0]['advances'] = [];
$phoneLots[0]['reserve_price'] = 0;
unset($phoneLots[1]);
$phone = LiveSaleBook::empty('store-phone');
$phone->apply('start_sale', ['lots' => array_values($phoneLots), 'at' => 't']);
$phone->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$phone->apply('open_lot', ['at' => 't', 'advances' => []]);
$phone->apply('accept_bid', [
    'amount' => 800,
    'source' => 'phone',
    'paddle_id' => 33,
    'customer_id' => 'phone-33',
    'at' => 't',
]);
check($phone->publicPayload('t')['histories'][0]['source'] === 'phone', 'a phone bid keeps its source');
check($phone->publicPayload('t')['histories'][0]['paddle_label'] === 'Phone #33', 'the room sees the phone paddle');

$settleLots = [
    [
        'lot_id' => 'lot-1',
        'lot_number' => 1,
        'starting_price' => 800,
        'reserve_price' => 0,
        'bid_incremental_settings' => ['increments' => [['from' => 0, 'to' => 10000, 'increment' => 100]]],
        'created_at' => '2026-10-07T00:00:00Z',
    ],
    [
        'lot_id' => 'lot-2',
        'lot_number' => 2,
        'starting_price' => 500,
        'reserve_price' => 0,
        'bid_incremental_settings' => ['increments' => [['from' => 0, 'to' => 10000, 'increment' => 100]]],
        'created_at' => '2026-10-07T00:00:00Z',
    ],
    [
        'lot_id' => 'lot-3',
        'lot_number' => 3,
        'starting_price' => 400,
        'reserve_price' => 0,
        'bid_incremental_settings' => ['increments' => [['from' => 0, 'to' => 10000, 'increment' => 100]]],
        'created_at' => '2026-10-07T00:00:00Z',
    ],
    [
        'lot_id' => 'lot-4',
        'lot_number' => 4,
        'starting_price' => 200,
        'reserve_price' => 0,
        'bid_incremental_settings' => ['increments' => [['from' => 0, 'to' => 10000, 'increment' => 50]]],
        'created_at' => '2026-10-07T00:00:00Z',
    ],
];
$settle = LiveSaleBook::empty('store-settle');
$settle->apply('start_sale', ['lots' => $settleLots, 'at' => 't']);
$settle->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$settle->apply('open_lot', ['at' => 't']);
$settle->apply('accept_bid', [
    'amount' => 800,
    'source' => 'floor',
    'paddle_id' => 10,
    'customer_id' => 'buyer-10',
    'at' => 't',
]);
$settle->apply('sell', ['at' => 't']);
$settle->apply('prepare_lot', ['lot_id' => 'lot-2', 'at' => 't']);
$settle->apply('open_lot', ['at' => 't']);
$settle->apply('pass', ['at' => 't']);
$settle->apply('prepare_lot', ['lot_id' => 'lot-3', 'at' => 't']);
$settle->apply('open_lot', ['at' => 't']);
$settle->apply('accept_bid', [
    'amount' => 400,
    'source' => 'floor',
    'paddle_id' => 11,
    'customer_id' => 'buyer-11',
    'at' => 't',
]);
$settle->apply('sell', ['at' => 't']);
$settle->apply('reopen', ['lot_id' => 'lot-3', 'at' => 't']);
check($settle->publicPayload('t')['histories'] === [], 'reopen clears the sold lot history');
check($settle->publicPayload('t')['asking_price'] === 400.0, 'reopen returns the sold lot to its opening price');
$settle->apply('pass', ['at' => 't']);
$settle->apply('prepare_lot', ['lot_id' => 'lot-4', 'at' => 't']);
$settle->apply('open_lot', ['at' => 't']);
$settle->apply('accept_bid', [
    'amount' => 200,
    'source' => 'floor',
    'paddle_id' => 7,
    'at' => 't',
]);
$settle->apply('sell', ['at' => 't']);
$settle->apply('end_sale', ['at' => 't']);
$settlement = $settle->settlement();
check(array_map(fn ($row) => $row['lot_id'], $settlement) === ['lot-1', 'lot-2', 'lot-3', 'lot-4'], 'settlement follows lot number');
check($settlement[0]['result'] === 'sold' && $settlement[0]['hammer_price'] === 800.0, 'a sold lot keeps the hammer');
check($settlement[0]['paddle_id'] === 10 && $settlement[0]['customer_id'] === 'buyer-10', 'a sold lot keeps the paddle and buyer');
check($settlement[1]['result'] === 'passed' && $settlement[1]['hammer_price'] === null, 'a passed lot has no hammer');
check($settlement[1]['customer_id'] === null && $settlement[1]['paddle_id'] === null, 'a passed lot has no buyer');
check($settlement[2]['result'] === 'passed' && $settlement[2]['hammer_price'] === null, 'a sold lot that is reopened and passed is not a hammer');
check($settlement[3]['result'] === 'sold' && $settlement[3]['customer_id'] === null, 'a floor hammer without a customer stays empty');
check($settlement[3]['paddle_id'] === 7, 'that hammer still names the paddle');
check($settle->publicPayload('t')['lots'][0]['hammer_paddle_label'] === 'Paddle #10', 'the room sees the paddle recorded at the hammer');
check(containsKey($settle->publicPayload('t'), 'settlement') === false, 'the ended public room has no settlement');
check(containsKey($settle->publicPayload('t'), 'book_can_take') === false, 'the ended public room has no book offer');
check(count($settle->clerkPayload('t')['settlement']) === 4, 'the clerk reviews the ended settlement');

$loadLots = [];
for ($i = 1; $i <= 180; $i++) {
    $loadLots[] = [
        'lot_id' => 'lot-' . $i,
        'lot_number' => $i,
        'starting_price' => 100,
        'reserve_price' => 0,
        'bid_incremental_settings' => [
            'increments' => [['from' => 0, 'to' => 100000, 'increment' => 10]],
        ],
        'created_at' => '2026-10-07T00:00:00Z',
    ];
}
$load = LiveSaleBook::empty('store-load');
$loadSequence = $load->sequence();
$loadStarted = microtime(true);
$load->apply('start_sale', ['lots' => $loadLots, 'at' => 't']);
$loadElapsed = microtime(true) - $loadStarted;
check($load->sequence() === $loadSequence + 1, 'starting a long catalogue is one sequence step');
check(count($load->publicPayload('t')['lots']) === 180, 'the catalogue is one snapshot');
check($loadElapsed < 2.0, 'a sale command does not dump the catalogue on a timer');
$load->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
check($load->sequence() === $loadSequence + 2, 'prepare is one sequence step');
$load->apply('open_lot', ['at' => 't']);
check($load->sequence() === $loadSequence + 3, 'open is one sequence step');
$load->apply('accept_bid', [
    'amount' => 100,
    'source' => 'floor',
    'paddle_id' => 1,
    'customer_id' => 'floor-1',
    'at' => 't',
]);
check($load->sequence() === $loadSequence + 4, 'a bid is one sequence step');

$clear = book();
$clear->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$clear->apply('open_lot', ['at' => 't']);
$clear->apply('accept_bid', [
    'amount' => 800,
    'source' => 'floor',
    'paddle_id' => 41,
    'customer_id' => 'floor-41',
    'at' => 't1',
]);
check($clear->publicPayload('t')['asking_price'] === 900.0, 'an advance does not answer the floor bid');
check(count($clear->publicPayload('t')['histories']) === 1, 'undo all still has one floor bid to clear');
$advanceBeforeClear = $clear->toArray()['lots'][0]['advance'];
$clear->apply('warn', ['action' => 'FAIR_WARNING', 'at' => 't2']);
$beforeUndoAll = $clear->sequence();
$clear->apply('undo_all_bids', ['at' => 't3']);
check($clear->sequence() === $beforeUndoAll + 1, 'undo all is one sequence step');
check($clear->publicPayload('t')['histories'] === [], 'undo all clears the ladder');
check($clear->publicPayload('t')['asking_price'] === 800.0, 'undo all returns to the opening price');
check($clear->publicPayload('t')['warning'] === null, 'undo all clears the warning');
check($clear->clerkPayload('t')['undo'] === null, 'undo all leaves nothing to undo');
check($clear->toArray()['lots'][0]['advance'] === $advanceBeforeClear, 'undo all does not change the absentee record');
check($clear->publicPayload('t')['lots'][0]['live_state'] === 'open', 'the lot stays open after undo all');
expect(fn () => $clear->apply('undo_all_bids', ['at' => 't']), 'no bid to undo');
$clearedAgain = LiveSaleBook::fromArray($clear->toArray());
expect(fn () => $clearedAgain->apply('undo_all_bids', ['at' => 't']), 'no bid to undo');

$drop = book();
expect(fn () => $drop->apply('remove_participant', ['at' => 't']), 'Choose a participant');
$drop->apply('remove_participant', [
    'customer_id' => 'absentee-55',
    'paddle_id' => 55,
    'at' => 't',
]);
check($drop->viewer('absentee-55')['removed'] === true, 'a removed customer stays out of later lots');
check($drop->viewer('floor-70')['removed'] === false, 'another customer is not removed');
check($drop->clerkPayload('t')['removed'][0]['customer_id'] === 'absentee-55', 'the clerk sees who left');
check($drop->clerkPayload('t')['removed'][0]['paddle_id'] === 55, 'the clerk sees the removed paddle');
check(containsKey($drop->publicPayload('t'), 'removed') === false, 'the public room does not list removed participants');
check(containsKey($drop->publicPayload('t'), 'customer_id') === false, 'removing a participant does not publish a customer id');
$drop->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$drop->apply('open_lot', [
    'at' => 't',
    'advances' => [[
        'customer_id' => 'absentee-55',
        'amount' => 1500,
        'paddle_id' => 55,
        'placed_at' => '2026-10-01T00:00:00Z',
    ]],
]);
check($drop->publicPayload('t')['histories'] === [], 'a removed absentee does not bid when the lot opens');
check($drop->clerkPayload('t')['highest_advanced_bid'] === null, 'a removed absentee is not shown as the book');
check($drop->clerkPayload('t')['book_can_take'] === false, 'the book cannot take a removed absentee');
$drop->apply('accept_bid', [
    'amount' => 800,
    'source' => 'floor',
    'paddle_id' => 70,
    'customer_id' => 'floor-70',
    'at' => 't2',
]);
check(count($drop->publicPayload('t')['histories']) === 1, 'a removed absentee does not answer a floor bid');
check($drop->publicPayload('t')['histories'][0]['paddle_label'] === 'Floor bid', 'the floor bid stays on the ladder');
expect(fn () => $drop->apply('accept_bid', [
    'amount' => 900,
    'source' => 'floor',
    'paddle_id' => 55,
    'customer_id' => 'absentee-55',
    'at' => 't3',
]), 'out of this sale');
expect(fn () => $drop->apply('submit_online_bid', [
    'amount' => 900,
    'customer_id' => 'absentee-55',
    'paddle_id' => 55,
    'at' => 't4',
]), 'out of this sale');
check($drop->viewer('absentee-55')['result'] === 'out', 'the removed customer is told they are out');
check($drop->viewer('floor-70')['result'] === 'with_you', 'another paddle still holds the bid');
$dropAgain = LiveSaleBook::fromArray($drop->toArray());
expect(fn () => $dropAgain->apply('submit_online_bid', [
    'amount' => 900,
    'customer_id' => 'absentee-55',
    'paddle_id' => 55,
    'at' => 't5',
]), 'out of this sale');

$kept = book();
$kept->setAutoAcceptOnlineBids(false);
$kept->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$kept->apply('open_lot', ['at' => 't', 'advances' => []]);
$kept->apply('submit_online_bid', [
    'amount' => 800,
    'customer_id' => 'online-a',
    'paddle_id' => 201,
    'at' => 't1',
]);
$kept->apply('submit_online_bid', [
    'amount' => 800,
    'customer_id' => 'online-b',
    'paddle_id' => 202,
    'at' => 't1',
]);
$kept->apply('remove_participant', [
    'customer_id' => 'online-a',
    'paddle_id' => 201,
    'at' => 't2',
]);
$pendingLeft = $kept->clerkPayload('t')['pending'];
check(count($pendingLeft) === 1 && $pendingLeft[0]['customer_id'] === 'online-b', 'removing a participant drops only their waiting bid');
$kept->apply('accept_pending', [
    'pending_id' => $pendingLeft[0]['id'],
    'at' => 't3',
]);
check($kept->publicPayload('t')['histories'][0]['paddle_label'] === 'Paddle #202', 'an accepted bid from someone else stays');
$kept->apply('remove_participant', [
    'customer_id' => 'online-b',
    'paddle_id' => 202,
    'at' => 't4',
]);
check(count($kept->publicPayload('t')['histories']) === 1, 'removing a participant leaves the accepted bid');
check($kept->viewer('online-b')['result'] === 'out', 'the leader who was removed cannot bid again');
expect(fn () => $kept->apply('remove_participant', [
    'customer_id' => 'online-b',
    'at' => 't5',
]), 'already out');

$rankLots = catalogue();
$rankLots[0]['advances'] = [
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
unset($rankLots[0]['advance']);
$rank = LiveSaleBook::empty('store-rank');
$rank->apply('start_sale', ['lots' => $rankLots, 'at' => 't']);
$rank->apply('remove_participant', ['customer_id' => 'high', 'paddle_id' => 11, 'at' => 't']);
$rank->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$rank->apply('open_lot', ['at' => 't']);
check($rank->publicPayload('t')['histories'] === [], 'one remaining absentee does not bid on open');
check($rank->clerkPayload('t')['highest_advanced_bid']['customer_id'] === 'low', 'the clerk book skips a removed absentee');
check($rank->clerkPayload('t')['highest_advanced_bid']['bid'] === 1200.0, 'the next absentee maximum is shown');
$rank->apply('pass', ['at' => 't']);
$rank->apply('end_sale', ['at' => 't']);
expect(fn () => $rank->apply('remove_participant', ['customer_id' => 'low', 'at' => 't']), 'ended');

$again = book();
$again->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$again->apply('open_lot', ['at' => 't', 'advances' => []]);
$again->apply('set_asking_price', ['amount' => 3100, 'at' => 't']);
$again->apply('pass', ['at' => 't']);
$again->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$again->apply('open_lot', ['at' => 't']);
check($again->publicPayload('t')['asking_price'] === 3100.0, 'opening a closed lot keeps a raised asking price');
$again->apply('accept_bid', [
    'amount' => 3100,
    'source' => 'floor',
    'paddle_id' => 7,
    'customer_id' => 'floor-7',
    'at' => 't',
]);
$beforeClose = $again->publicPayload('t');
$again->apply('pass', ['at' => 't']);
$again->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$again->apply('open_lot', ['at' => 't']);
$reopened = $again->publicPayload('t');
check($reopened['asking_price'] === $beforeClose['asking_price'], 'opening a closed lot keeps the asking price');
check($reopened['asking_price'] === 3200.0, 'the asking price stays one increment above the last bid');
check($reopened['lots'][0]['current_bid'] === 3100.0, 'the last bid stays on the reopened lot');
check(count($reopened['histories']) === count($beforeClose['histories']), 'opening a closed lot does not replay bids');
$again->apply('pass', ['at' => 't']);
$again->apply('prepare_lot', ['lot_id' => 'lot-2', 'at' => 't']);
$again->apply('open_lot', ['at' => 't']);
check($again->publicPayload('t')['asking_price'] === 500.0, 'a lot opened for the first time still starts at its opening price');

$returnLots = catalogue();
unset($returnLots[0]['advance']);
$returnLots[0]['advances'] = [];
$returnLots[0]['reserve_price'] = 0;
$return = LiveSaleBook::empty('store-return');
$return->apply('start_sale', ['lots' => $returnLots, 'at' => 't']);
$return->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$return->apply('open_lot', ['at' => 't', 'advances' => []]);
$return->apply('accept_bid', [
    'amount' => 800,
    'source' => 'floor',
    'paddle_id' => 7,
    'customer_id' => 'floor-7',
    'at' => 't',
]);
$return->apply('warn', ['action' => 'FAIR_WARNING', 'at' => 't']);
$return->apply('pass', ['at' => 't']);
$closed = $return->publicPayload('t');
expect(fn () => $return->apply('open_lot', ['lot_id' => 'lot-1', 'at' => 't']), 'Prepare the lot');
expect(fn () => $return->apply('undo_latest_bid', ['at' => 't']), 'Open the lot');
check($return->publicPayload('t')['asking_price'] === $closed['asking_price'], 'a refused open leaves the asking price');
check($return->publicPayload('t')['lots'][0]['current_bid'] === 800.0, 'a refused open leaves the bid');
check($return->publicPayload('t')['lots'][0]['live_state'] === 'passed', 'a refused open leaves the lot closed');
$return->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$return->apply('open_lot', ['at' => 't']);
$back = $return->publicPayload('t');
check($back['asking_price'] === 900.0, 'opening after a warning keeps the asking price');
check($back['warning'] === null, 'opening a closed lot clears the warning');
check($back['lots'][0]['current_bid'] === 800.0 && $back['lots'][0]['live_state'] === 'open', 'opening a closed lot keeps the bid');
expect(fn () => $return->apply('submit_online_bid', [
    'amount' => 800,
    'customer_id' => 'online-a',
    'paddle_id' => 201,
    'at' => 't',
]), 'asking price');
$return->apply('submit_online_bid', [
    'amount' => 900,
    'customer_id' => 'online-a',
    'paddle_id' => 201,
    'at' => 't',
]);
check($return->publicPayload('t')['lots'][0]['current_bid'] === 900.0, 'the continued asking price can be bid');
$return->apply('pass', ['at' => 't']);
$return->apply('prepare_lot', ['lot_id' => 'lot-2', 'at' => 't']);
$return->apply('open_lot', ['at' => 't']);
check($return->publicPayload('t')['asking_price'] === 500.0, 'the next lot still opens at its starting price');
check($return->publicPayload('t')['lots'][0]['current_bid'] === 900.0, 'the closed lot keeps its bid while another lot is open');
$return->apply('accept_bid', [
    'amount' => 500,
    'source' => 'floor',
    'paddle_id' => 8,
    'customer_id' => 'floor-8',
    'at' => 't',
]);
$return->apply('pass', ['at' => 't']);
$return->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$return->apply('open_lot', ['at' => 't']);
$home = $return->publicPayload('t');
check($home['current_lot_id'] === 'lot-1' && $home['asking_price'] === 1000.0, 'coming back continues the closed lot');
check($home['lots'][0]['current_bid'] === 900.0, 'coming back keeps that lot bid');
check($home['lots'][1]['current_bid'] === 500.0 && $home['lots'][1]['live_state'] === 'passed', 'the lot left behind stays closed at its bid');

$soldLots = catalogue();
unset($soldLots[0]['advance']);
$soldLots[0]['reserve_price'] = 0;
$sold = LiveSaleBook::empty('store-sold-open');
$sold->apply('start_sale', ['lots' => $soldLots, 'at' => 't']);
$sold->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$sold->apply('open_lot', ['at' => 't', 'advances' => []]);
$sold->apply('accept_bid', [
    'amount' => 800,
    'source' => 'floor',
    'paddle_id' => 7,
    'customer_id' => 'floor-7',
    'at' => 't',
]);
$sold->apply('sell', ['at' => 't']);
$held = $sold->publicPayload('t');
check($held['lots'][0]['live_state'] === 'sold', 'the lot is sold before it is prepared');
check($held['lots'][0]['current_bid'] === 800.0, 'the hammer is the accepted bid');
$sold->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$preparing = $sold->publicPayload('t');
check($preparing['lots'][0]['live_state'] === 'preparing', 'a sold lot can be prepared');
check($preparing['asking_price'] === $held['asking_price'], 'preparing a sold lot keeps the asking price');
check($preparing['lots'][0]['current_bid'] === 800.0, 'preparing keeps the bid');
check($sold->toArray()['lots'][0]['hammer_price'] === null, 'preparing clears the hammer');
$sold->apply('open_lot', ['at' => 't']);
$opened = $sold->publicPayload('t');
check($opened['lots'][0]['live_state'] === 'open', 'opening a prepared sold lot puts it back');
check($opened['asking_price'] === $held['asking_price'], 'prepare and open keep the asking price');
check($opened['lots'][0]['current_bid'] === 800.0, 'the bid stays');
check(count($opened['histories']) === 1, 'opening a sold lot does not replay bids');
$settled = array_column($sold->clerkPayload('t')['settlement'], 'lot_id');
check(!in_array('lot-1', $settled, true), 'the lot is no longer a hammer');


$steps = book();
$steps->apply('prepare_lot', ['lot_id' => 'lot-2', 'at' => 't']);
$steps->apply('open_lot', ['at' => 't', 'advances' => []]);
check($steps->publicPayload('t')['asking_price'] === 500.0, 'lot 2 opens at its starting price');
$steps->apply('set_starting_price', ['lot_id' => 'lot-1', 'amount' => 700, 'at' => 't']);
check($steps->toArray()['lots'][0]['starting_price'] === 700.0, 'the starting price of an upcoming lot can be updated');
check($steps->toArray()['lots'][0]['asking_price'] === null, 'an upcoming lot keeps a null asking price');
$steps->apply('set_starting_price', ['amount' => 1000, 'at' => 't']);
check($steps->publicPayload('t')['asking_price'] === 1000.0, 'updating the opening price moves the ask when no bid has been taken');
check($steps->toArray()['lots'][1]['opening_asking'] === 1000.0, 'the recorded opening ask follows the new starting price');
expect(fn () => $steps->apply('set_starting_price', ['amount' => -1, 'at' => 't']), 'zero or more');
$ladder = [
    1000 => 1100.0,
    2000 => 2200.0,
    2800 => 3000.0,
    3000 => 3200.0,
    3200 => 3500.0,
    3500 => 3800.0,
    3800 => 4000.0,
    4000 => 4200.0,
    4800 => 5000.0,
    5000 => 5500.0,
    9500 => 10000.0,
    80000 => 85000.0,
    1000000 => 1050000.0,
    1500000 => 1550000.0,
    2000000 => 2050000.0,
];
foreach ($ladder as $bid => $ask) {
    $steps->apply('accept_bid', [
        'amount' => $bid,
        'source' => 'floor',
        'at' => 't',
    ]);
    check(
        $steps->publicPayload('t')['asking_price'] === $ask,
        'a bid of ' . $bid . ' asks ' . $ask . ' got ' . $steps->publicPayload('t')['asking_price']
    );
}
expect(fn () => $steps->apply('sell', ['at' => 't']), 'paddle number');
$steps->apply('sell', ['paddle_id' => 33, 'customer_id' => 'phone-33', 'at' => 't']);
check($steps->publicPayload('t')['lots'][1]['hammer_paddle_label'] === 'Paddle #33', 'selling records the paddle');
expect(fn () => $steps->apply('set_starting_price', ['amount' => 1, 'at' => 't']), 'no longer on the block');

$small = book();
$small->apply('prepare_lot', ['lot_id' => 'lot-2', 'at' => 't']);
$small->apply('open_lot', ['at' => 't', 'advances' => []]);
$small->apply('set_starting_price', ['amount' => 5, 'at' => 't']);
check($small->publicPayload('t')['asking_price'] === 5.0, 'a starting price below 10 is kept');
$small->apply('accept_bid', ['amount' => 5, 'source' => 'floor', 'at' => 't']);
check($small->publicPayload('t')['asking_price'] === 6.0, 'below 10 the ladder steps by 1');
$small->apply('accept_bid', ['amount' => 9, 'source' => 'floor', 'at' => 't']);
check($small->publicPayload('t')['asking_price'] === 10.0, 'the step below 10 reaches 10');

$bands = LiveSaleBook::empty('store-bands');
$bands->apply('start_sale', [
    'lots' => [[
        'lot_id' => 'lot-band',
        'lot_number' => 1,
        'starting_price' => 1200000,
        'reserve_price' => 0,
        'bid_incremental_settings' => [
            'increments' => [
                ['from' => 1000000, 'to' => 2000000, 'increment' => 100000],
                ['from' => 2000000, 'to' => 3000000, 'increment' => 200000],
            ],
        ],
        'created_at' => 't',
    ]],
    'at' => 't',
]);
$bands->apply('prepare_lot', ['at' => 't']);
$bands->apply('open_lot', ['at' => 't', 'advances' => []]);
check($bands->publicPayload('t')['asking_price'] === 1200000.0, 'the banded lot opens at its starting price');
$bands->apply('accept_bid', ['amount' => 1200000, 'source' => 'floor', 'at' => 't']);
check($bands->publicPayload('t')['asking_price'] === 1300000.0, 'a bid uses the lot increment instead of 50000');
$bands->apply('set_asking_price', ['amount' => 2400000, 'at' => 't']);
$bands->apply('accept_bid', ['amount' => 2400000, 'source' => 'floor', 'at' => 't']);
check($bands->publicPayload('t')['asking_price'] === 2600000.0, 'a bid on a rung advances by the full increment');
$bands->apply('set_asking_price', ['amount' => 2450000, 'at' => 't']);
check($bands->publicPayload('t')['asking_price'] === 2450000.0, 'a typed asking price can sit off the ladder');
$bands->apply('accept_bid', ['amount' => 2450000, 'source' => 'floor', 'at' => 't']);
check($bands->publicPayload('t')['asking_price'] === 2600000.0, 'an off-ladder bid returns to the next rung');
$bands->apply('set_increment', ['increment' => 50000, 'at' => 't']);
check($bands->publicPayload('t')['asking_price'] === 2500000.0, 'a typed increment replaces the lot band');

$ranges = \Starsnet\Project\Paraqon\App\LiveSale\LiveSaleService::class;
check($ranges::expandPrinterRange('1-3,6,9,10-11') === [1, 2, 3, 6, 9, 10, 11], 'a printer range expands');
check($ranges::expandPrinterRange('  ') === [], 'an empty range expands to nothing');
check($ranges::expandPrinterRange('4, 2,4') === [2, 4], 'a printer range drops duplicates');
expect(fn () => $ranges::expandPrinterRange('3-1'), '1-3,6,9,10-11');
expect(fn () => $ranges::expandPrinterRange('1-3,,6'), '1-3,6,9,10-11');
check($ranges::paddleMayBidLot(['non_high_value_lot_ids' => [], 'non_high_value_paddle_numbers' => []], 'lot-1', 5) === true, 'an empty pair leaves every lot open');
check($ranges::paddleMayBidLot(['non_high_value_lot_ids' => ['lot-1'], 'non_high_value_paddle_numbers' => []], 'lot-9', 5) === true, 'listed lots do nothing until a paddle is listed');
check($ranges::paddleMayBidLot(['non_high_value_lot_ids' => ['lot-1'], 'non_high_value_paddle_numbers' => [9]], 'lot-1', 9) === true, 'a listed paddle can bid a non-high-value lot');
check($ranges::paddleMayBidLot(['non_high_value_lot_ids' => ['lot-1'], 'non_high_value_paddle_numbers' => [9]], 'lot-9', 9) === false, 'a listed paddle cannot bid a high-value lot');
check($ranges::paddleMayBidLot(['non_high_value_lot_ids' => [], 'non_high_value_paddle_numbers' => [9]], 'lot-1', 9) === false, 'listed paddles are closed out of every lot when none is exempt');
check($ranges::paddleMayBidLot(['non_high_value_lot_ids' => [], 'non_high_value_paddle_numbers' => [8]], 'lot-1', 9) === true, 'a paddle outside the list can bid every lot');
check($ranges::paddleMayBidLot(['non_high_value_lot_ids' => [], 'non_high_value_paddle_numbers' => [9]], 'lot-1', null) === true, 'a missing paddle is not on the list');

$batchPaddles = $ranges::expandPrinterRange('1-40,80-139');
$batchLots = $ranges::expandPrinterRange('101,102,110,118');
check($batchPaddles === array_merge(range(1, 40), range(80, 139)), 'the jewelry paddle batch expands');
check($batchLots === [101, 102, 110, 118], 'the jewelry lot batch expands');
$batchOpen = [
    '6ae98f45ee40cbcc320305ca',
    '6ae98e1baead010c6702edba',
    '6ae99704136389b7bb0f879f',
    '6ae99bd5136389b7bb0f87a4',
];
$batchStore = [
    'non_high_value_paddle_numbers' => $batchPaddles,
    'non_high_value_lot_ids' => $batchOpen,
];
check($ranges::paddleMayBidLot($batchStore, $batchOpen[0], 1) === true, 'paddle 1 can bid lot 101');
check($ranges::paddleMayBidLot($batchStore, 'other-lot', 1) === false, 'paddle 1 cannot bid another lot');
check($ranges::paddleMayBidLot($batchStore, 'other-lot', 40) === false, 'paddle 40 is in the batch');
check($ranges::paddleMayBidLot($batchStore, 'other-lot', 41) === true, 'paddle 41 is outside the batch');
check($ranges::paddleMayBidLot($batchStore, 'other-lot', 79) === true, 'paddle 79 is outside the batch');
check($ranges::paddleMayBidLot($batchStore, $batchOpen[2], 80) === true, 'paddle 80 can bid lot 110');
check($ranges::paddleMayBidLot($batchStore, 'other-lot', 80) === false, 'paddle 80 cannot bid a high-value lot');
check($ranges::paddleMayBidLot($batchStore, $batchOpen[3], 139) === true, 'paddle 139 can bid lot 118');
check($ranges::paddleMayBidLot($batchStore, 'other-lot', 139) === false, 'paddle 139 cannot bid a high-value lot');
check($ranges::paddleMayBidLot($batchStore, 'other-lot', 140) === true, 'paddle 140 is outside the batch');
check($ranges::paddleMayBidLot($batchStore, 'other-lot', 101) === false, 'paddle 101 is in the batch');
check($ranges::paddleMayBidLot($batchStore, $batchOpen[1], 8001) === true, 'paddle 8001 can bid lot 102');
check($ranges::paddleMayBidLot($batchStore, 'other-lot', 8002) === true, 'paddle 8002 can bid every lot');

$later = book();
$later->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$later->apply('open_lot', ['at' => 't', 'advances' => []]);
$later->apply('accept_bid', [
    'amount' => 800,
    'source' => 'floor',
    'paddle_id' => 4,
    'customer_id' => 'floor-4',
    'at' => 't',
]);
$later->apply('sell', ['paddle_id' => 4, 'customer_id' => 'floor-4', 'at' => 't']);
$later->apply('prepare_lot', ['lot_id' => 'lot-2', 'at' => 't']);
$later->apply('open_lot', ['at' => 't']);
$later->apply('pass', ['at' => 't']);
$later->apply('reopen', ['lot_id' => 'lot-1', 'at' => 't']);
check($later->currentLotId() === 'lot-1', 'reopen makes the earlier sold lot current');
check($later->publicPayload('t')['asking_price'] === 800.0, 'reopening an earlier lot returns to its opening price');
check($later->publicPayload('t')['histories'] === [], 'reopening an earlier lot clears its bid history');
check($later->publicPayload('t')['lots'][0]['live_state'] === 'open', 'the earlier lot is back on the block');
check($later->publicPayload('t')['lots'][1]['live_state'] === 'passed', 'the later lot stays passed');

$skipped = book();
$skipped->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$skipped->apply('pass', ['at' => 't']);
expect(fn () => $skipped->apply('reopen', ['lot_id' => 'lot-1', 'at' => 't']), 'never opened');

$busy = book();
$busy->apply('prepare_lot', ['lot_id' => 'lot-1', 'at' => 't']);
$busy->apply('open_lot', ['at' => 't', 'advances' => []]);
$busy->apply('accept_bid', [
    'amount' => 800,
    'source' => 'floor',
    'paddle_id' => 4,
    'customer_id' => 'floor-4',
    'at' => 't',
]);
$busy->apply('sell', ['paddle_id' => 4, 'customer_id' => 'floor-4', 'at' => 't']);
$busy->apply('prepare_lot', ['lot_id' => 'lot-2', 'at' => 't']);
$busy->apply('open_lot', ['at' => 't']);
expect(fn () => $busy->apply('reopen', ['lot_id' => 'lot-1', 'at' => 't']), 'on the block');

echo "ok\n";

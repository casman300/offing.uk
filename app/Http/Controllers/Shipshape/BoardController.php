<?php

namespace App\Http\Controllers\Shipshape;

use App\Http\Controllers\Controller;
use App\Models\Chore;
use App\Models\ChoreList;
use App\Support\Shipshape\Board;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /shipshape — every list you can see, merged and sorted by urgency
 * (spec §25), not grouped by list: that's what a chore board is for.
 */
class BoardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $today = $user->today();
        $lists = Board::visibleLists($user);

        return Inertia::render('shipshape/board', [
            'today' => $today->toDateString(),
            'lists' => $lists->map(fn (ChoreList $list) => Board::listSummary($list, $user))->values(),
            'chores' => Board::choresOn($lists)
                ->map(fn (Chore $chore) => Board::chore($chore, $user, $today))
                ->sortBy([['days', 'asc'], ['title', 'asc']])
                ->values(),
        ]);
    }
}

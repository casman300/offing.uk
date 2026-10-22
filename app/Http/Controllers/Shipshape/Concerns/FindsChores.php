<?php

namespace App\Http\Controllers\Shipshape\Concerns;

use App\Models\Chore;
use App\Models\ChoreList;
use App\Models\User;

/**
 * A list or chore you can't see is a 404, never a 403 that confirms it
 * exists. Editing additionally needs the list's §7 edit switch to allow you.
 */
trait FindsChores
{
    protected function viewableList(User $user, int $id): ChoreList
    {
        $list = ChoreList::query()->findOrFail($id);
        abort_unless($list->viewableBy($user), 404);

        return $list;
    }

    protected function editableList(User $user, int $id): ChoreList
    {
        $list = $this->viewableList($user, $id);
        abort_unless($list->editableBy($user), 403);

        return $list;
    }

    protected function viewableChore(User $user, int $id, bool $withTrashed = false): Chore
    {
        $chore = Chore::query()->when($withTrashed, fn ($q) => $q->withTrashed())->with('list')->findOrFail($id);
        abort_unless($chore->list !== null && $chore->list->viewableBy($user), 404);

        return $chore;
    }

    protected function editableChore(User $user, int $id, bool $withTrashed = false): Chore
    {
        $chore = $this->viewableChore($user, $id, $withTrashed);
        abort_unless($chore->list->editableBy($user), 403);

        return $chore;
    }
}

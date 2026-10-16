<?php

namespace App\Http\Controllers;

use App\Feeds\UserFeed;
use App\Models\FeedToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /feed/{token}.ics: the public, secret-URL side of #15.
 *
 * It sits outside the `web` middleware group (see routes/feeds.php), so a
 * calendar app polling every few minutes never gets a session or cookie.
 * Unknown, revoked and malformed tokens all get the same 404.
 *
 * Deliberately not behind the birthday-engine flag: this is the user's
 * calendar sync, and switching a flag off mustn't empty subscribed calendars.
 */
class FeedController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $feedToken = FeedToken::findActive($token);
        $subject = $feedToken?->subject;

        abort_unless($subject instanceof User, 404);

        $feedToken->recordUse($request->userAgent());

        $body = (new UserFeed($subject))->render();
        $etag = '"'.hash('sha256', $body).'"';

        $headers = [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="offing.ics"',
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ];

        if (in_array($etag, $request->getETags(), true)) {
            return response('', 304, $headers);
        }

        return response($body, 200, $headers);
    }
}

# Subscribing to and importing calendars

You can bring other calendars into Offing in two ways:

- **Subscribe** to a calendar's address, such as your work calendar, a school's term dates or a club's fixtures. Offing checks it about once an hour and keeps it up to date.
- **Import** an `.ics` file once. Its events become your own, and you can change them.

## Subscribing

1. Find the calendar's iCal address. It usually ends in `.ics` or starts with `webcal://`:
   - **Google Calendar**: on a computer, open **Settings**, pick the calendar under "Settings for my calendars", then go to **Integrate calendar → Secret address in iCal format**.
   - **Outlook**: **Settings → Calendar → Shared calendars → Publish a calendar**, then copy the ICS link.
   - **Apple iCloud**: share the calendar as a **Public Calendar** and copy its link.
   - Clubs and schools (TeamSnap, Spond and others) usually have a "subscribe" or "export to calendar" link.
2. In Offing, go to **Settings → Subscriptions**.
3. Paste the address, give the calendar a name and a colour, and choose **Subscribe**.

Offing fetches the calendar straight away, so you'll see right away whether it worked. Its events appear on your calendar in the colour you chose. You can turn them on and off with the calendar's button above the calendar, like any other calendar.

Subscribed events are **read-only**. To change one, change it where it comes from, and Offing picks up the change within the hour. If you can't wait, use **Refresh now**. If something is removed or cancelled at the source, it disappears from Offing too.

You can rename or recolour a subscription at any time. **Remove** takes its events off your Offing calendar but doesn't change anything at the source.

Only you see a subscription and its events, even on a crew. They do appear in your own [calendar feed](Calendar-feeds.md) links.

### Keeping addresses safe

A secret address, like Google's, lets anyone who has it read the whole calendar. Offing keeps it encrypted and never shows it again after you've saved it. Settings shows only the website it comes from. If you think a secret address has leaked, reset it at the source, then remove the subscription and add the new address.

### When something goes wrong

If Offing can't fetch a calendar, its card in Settings says why. For example, the address may have been reset (403), the calendar may have been deleted (404), or the server may not be answering. Offing keeps trying, waiting longer between attempts (up to once a day), and goes back to hourly once it works again.

Offing only fetches calendars from the public internet over `http` or `https`. A `webcal://` address is fetched over `https`. Addresses on private networks aren't allowed.

If a calendar has events Offing can't read, it skips them and shows a note. The rest still come in.

## Importing a file

1. Go to **Calendar → Calendars → Import an .ics file**.
2. Choose the file and the calendar to add its events to.
3. Choose **Check the file**. Offing shows how many events it found, the dates they cover, and anything it can't read. Nothing has been imported yet.
4. Choose **Import**.

Imported events are ordinary events: you can edit, move and delete them. Importing the same file again doesn't create duplicates. Events that are already in that calendar are left alone.

Files can be up to 5 MB, with up to 5,000 events.

## What comes across

Offing understands times in any timezone (including Outlook's Windows timezone names), all-day and multi-day events, repeating events with skipped and moved dates, private events and "show me as free". Times written without a timezone are read in your profile timezone.

Anything else in an event, such as attendees, attachments, conference links and alerts, is kept as it came. It's passed on unchanged when the event goes out in your calendar feed.

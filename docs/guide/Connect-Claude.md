# Connect Claude

You can link your own Claude to Offing, then ask it things like "whose birthday is next?" or "what chores are overdue?". This works in Claude on the web, the desktop app, the phone apps and Claude Code.

Connect Claude is still being tried out, so you'll only see it if it's been switched on for you.

## Connecting

1. In Offing, go to **Settings → Connect Claude** and copy the connector address.
2. In Claude on the web or the desktop app, go to **Settings → Connectors**, choose **Add custom connector**, name it "Offing" and paste the address. Then choose **Connect**.
3. Offing asks you to sign in, if you aren't already, and then asks what Claude may do. Choose, then **Allow**.

Once it's added on the web or the desktop app, it's on your phone too.

In Claude Code, run `claude mcp add --transport http offing <the address>`, then `/mcp` and sign in.

If you sign in with an email link, open the link on the same device and browser you're connecting from. A passkey avoids the problem altogether.

## What Claude can do

When you connect, you choose one of these:

- **Read only**: Claude can look things up but not change anything.
- **Read and add new**: Claude can also add new things, such as a person or a chore you've done.
- **Read, add and change**: Claude can also change or remove things that are already there.

You also choose whether Claude sees your **private events**. If you leave this off, Claude sees them only as "busy", the same as your partner would.

At the moment Claude can read a briefing: your birthdays coming up, your people, your crews and your Shipshape chores. Adding and changing things comes next.

Claude sees what you can see in Offing. Anything it reads is sent to Anthropic, the company that runs Claude, under their privacy policy.

## Changing or disconnecting

**Settings → Connect Claude** lists everything you've connected and when each was last used. You can change what each one can do, and the change applies straight away. To disconnect, choose the bin icon. Claude loses access immediately. You can connect again from Claude whenever you like.

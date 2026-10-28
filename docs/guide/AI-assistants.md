# AI assistants

You can connect your own AI assistant to Offing, then ask it things like "whose birthday is next?" or "what chores are overdue?".

We test with Claude (on the web, the desktop app, the phone apps and Claude Code). Offing uses MCP, an open standard for connecting assistants to apps, so ChatGPT and other assistants that support MCP connectors should work too.

This is still being tried out, so you'll only see it if it's been switched on for you.

## Connecting

First, in Offing, go to **Settings → Connected apps** and copy the connector address. Then:

- **Claude:** on the web or in the desktop app, go to **Settings → Connectors**, choose **Add custom connector**, name it "Offing" and paste the address. Then choose **Connect**. Once it's added there, it's on your phone too.
- **ChatGPT:** go to **Settings → Apps & Connectors** and add a connector with the address. Depending on your plan, you may need to turn on developer mode first.
- **Claude Code:** run `claude mcp add --transport http offing <the address>`, then `/mcp` and sign in.
- **Other assistants:** look for an option to add a remote MCP connector (sometimes called an MCP server) that signs in with OAuth, and use the same address.

Offing then asks you to sign in, if you aren't already, and asks what the assistant may do. Choose, then **Allow**.

If you sign in with an email link, open the link on the same device and browser you're connecting from. A passkey avoids the problem altogether.

## What an assistant can do

When you connect, you choose one of these:

- **Read only**: it can look things up but not change anything.
- **Read and add new** (the default): it can also add new things, such as a person or a chore you've done.
- **Read, add and change**: it can also change or remove things that are already there.

You also choose whether it sees your **private events**. If you leave this off, it sees them only as "busy", the same as your partner would.

If you use [Barometer](Barometer.md), there's a separate switch for it, off unless you turn it on. You can change it later in **Settings → Connected apps**.

At the moment an assistant can:

- **Read a briefing**: your birthdays coming up, your people, your crews and your Shipshape chores.
- **Look things up**: search by name or words, see what's due or whose birthday falls between two dates, or get everything about one person, crew or chore.
- **Add or update people** in your own address book, birthdays included ("add Sue, her birthday's 25 October"). Changing someone already there needs **Read, add and change**. Deleting people stays in Offing.
- **Log a chore** done or skipped ("I've done the bins", "Alfie did the hoovering yesterday"). Undoing one needs **Read, add and change**. Creating and editing chores stays in Offing.
- **Barometer**, only if you switched it on for that assistant:
  - **Log a reading** you tell it about ("log a 7, crowded train"), or a second rating for today's latest reading ("I'm down to a 3 now"). This needs **Read and add new**.
  - **Give you summary numbers**: how many readings, average ratings, how much they drop by the second rating, week by week, and by time of day and weekday.
  - It can't read your readings or anything you wrote in them, and it can't change or delete them. Use Offing for that.

Calendar events come later, as they arrive in Offing.

Chores logged by an assistant show "via an AI assistant" in the chore's log, and every change is recorded in Offing's audit log.

An assistant sees what you can see in Offing. Anything it reads is sent to the company that runs it (Anthropic for Claude, OpenAI for ChatGPT), under their privacy policy.

## Changing or disconnecting

**Settings → Connected apps** lists everything you've connected and when each was last used. You can change what each one can do, and the change applies straight away. To disconnect, choose the bin icon. The assistant loses access immediately. You can connect it again whenever you like.

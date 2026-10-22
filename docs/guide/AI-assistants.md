# AI assistants

You can connect your own AI assistant to Offing, then ask it things like "whose birthday is next?" or "what chores are overdue?".

We test with Claude (on the web, the desktop app, the phone apps and Claude Code). Offing uses MCP, an open standard for connecting assistants to apps, so ChatGPT and other assistants that support MCP connectors should work too.

This is still being tried out, so you'll only see it if it's been switched on for you.

## Connecting

First, in Offing, go to **Settings → AI assistants** and copy the connector address. Then:

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

At the moment an assistant can read a briefing: your birthdays coming up, your people, your crews and your Shipshape chores. Adding and changing things comes next.

An assistant sees what you can see in Offing. Anything it reads is sent to the company that runs it (Anthropic for Claude, OpenAI for ChatGPT), under their privacy policy.

## Changing or disconnecting

**Settings → AI assistants** lists everything you've connected and when each was last used. You can change what each one can do, and the change applies straight away. To disconnect, choose the bin icon. The assistant loses access immediately. You can connect it again whenever you like.

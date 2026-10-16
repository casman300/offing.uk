<?php

/**
 * Builds the GitHub wiki from docs/.
 *
 *   php .github/wiki/build.php [--repo=owner/name] [--ref=main] [--app-url=https://offing.uk] <out-dir>
 *
 * docs/ is the source of truth; the wiki is a generated, one-way mirror.
 *
 * - Every docs/**.md becomes one flat wiki page named after its file
 *   (docs/guide/Sharing.md -> "Sharing"), so basenames must be unique.
 * - docs/Home.md is the wiki landing page (user-facing).
 * - docs/guide/*.md are listed under "Guide" in the generated _Sidebar;
 *   everything else is listed under "Development".
 * - Relative links to other docs become wiki links; relative links to other
 *   repo files become GitHub blob links at --ref; root-relative links
 *   (/people) become app links at --app-url, so the same markdown can be
 *   rendered in-app at /docs later (SPEC §20). Broken links fail the build.
 * - Each page gets a footer pointing back at its source file.
 */
$options = getopt('', ['repo:', 'ref:', 'app-url:'], $rest);
$outDir = $argv[$rest] ?? null;

if ($outDir === null) {
    fwrite(STDERR, "usage: php .github/wiki/build.php [--repo=owner/name] [--ref=main] [--app-url=URL] <out-dir>\n");
    exit(2);
}

$repo = $options['repo'] ?? 'casman300/offing.uk';
$ref = $options['ref'] ?? 'main';
$appUrl = rtrim($options['app-url'] ?? 'https://offing.uk', '/');

$root = dirname(__DIR__, 2);
$docsDir = $root.'/docs';
$blobBase = "https://github.com/{$repo}/blob/{$ref}/";

/** @var array<string, array{path: string, page: string, title: string, section: string}> $pages keyed by repo-relative path */
$pages = [];
$byName = [];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docsDir, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'md') {
        continue;
    }

    $path = 'docs/'.str_replace('\\', '/', substr($file->getPathname(), strlen($docsDir) + 1));
    $page = $file->getBasename('.md');

    if (! preg_match('/^[A-Za-z0-9_-]+$/', $page)) {
        fail("{$path}: wiki page names may only use letters, digits, '-' and '_'");
    }
    if (isset($byName[$page])) {
        fail("{$path}: page name '{$page}' clashes with {$byName[$page]} (the wiki is flat, so basenames must be unique)");
    }
    $byName[$page] = $path;

    preg_match('/^# (.+)$/m', (string) file_get_contents($file->getPathname()), $m);

    $pages[$path] = [
        'path' => $path,
        'page' => $page,
        'title' => trim($m[1] ?? str_replace('-', ' ', $page)),
        'section' => str_starts_with($path, 'docs/guide/') ? 'Guide' : 'Development',
    ];
}

if (! isset($pages['docs/Home.md'])) {
    fail('docs/Home.md is missing — it becomes the wiki landing page');
}

ksort($pages);

if (! is_dir($outDir) && ! mkdir($outDir, 0755, true)) {
    fail("cannot create {$outDir}");
}

$errors = [];

foreach ($pages as $path => $meta) {
    $source = (string) file_get_contents($root.'/'.$path);
    $body = rewriteLinks($source, $path, $pages, $root, $blobBase, $appUrl, $errors);
    $body = rtrim($body)."\n\n---\n\n_This page is generated from [`{$path}`]({$blobBase}{$path}). "
        ."Edit it there — changes made in the wiki are overwritten._\n";

    file_put_contents("{$outDir}/{$meta['page']}.md", $body);
}

$sidebar = "**[Home](Home)**\n";
foreach (['Guide', 'Development'] as $section) {
    $entries = array_filter($pages, fn ($p) => $p['section'] === $section && $p['page'] !== 'Home');
    if ($entries === []) {
        continue;
    }
    $sidebar .= "\n**{$section}**\n\n";
    foreach ($entries as $p) {
        $sidebar .= "- [{$p['title']}]({$p['page']})\n";
    }
}
file_put_contents("{$outDir}/_Sidebar.md", $sidebar);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "error: {$error}\n");
    }
    exit(1);
}

echo 'Built '.count($pages)." wiki page(s) into {$outDir}\n";

/**
 * Rewrites inline markdown link targets, leaving fenced and inline code alone.
 *
 * @param  array<string, array{path: string, page: string, title: string, section: string}>  $pages
 * @param  list<string>  $errors
 */
function rewriteLinks(string $source, string $path, array $pages, string $root, string $blobBase, string $appUrl, array &$errors): string
{
    $parts = preg_split('/(^```.*?^```[^\n]*$|`[^`\n]*`)/ms', $source, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$source];

    foreach ($parts as $i => $part) {
        if (str_starts_with($part, '`')) {
            continue;
        }

        $parts[$i] = preg_replace_callback('/(\]\()([^)\s]+)((?:\s+"[^"]*")?\))/', function ($m) use ($path, $pages, $root, $blobBase, $appUrl, &$errors) {
            $target = $m[2];

            if (preg_match('#^([a-z][a-z0-9+.-]*:|\#)#i', $target)) {
                return $m[0]; // absolute URL, mailto:, or same-page anchor
            }

            if (str_starts_with($target, '/')) {
                return $m[1].$appUrl.$target.$m[3]; // app route
            }

            [$file, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $resolved = normalise(dirname($path).'/'.$file);
            $suffix = $anchor === null ? '' : '#'.$anchor;

            if (isset($pages[$resolved])) {
                return $m[1].$pages[$resolved]['page'].$suffix.$m[3];
            }

            if ($resolved !== null && file_exists($root.'/'.$resolved)) {
                return $m[1].$blobBase.$resolved.$suffix.$m[3];
            }

            $errors[] = "{$path}: broken link '{$target}'";

            return $m[0];
        }, $part);
    }

    return implode('', $parts);
}

/** Collapses ./ and ../ segments; null if the path escapes the repo. */
function normalise(string $path): ?string
{
    $out = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($out === []) {
                return null;
            }
            array_pop($out);

            continue;
        }
        $out[] = $segment;
    }

    return implode('/', $out);
}

function fail(string $message): never
{
    fwrite(STDERR, "error: {$message}\n");
    exit(1);
}

# phpstan-type-trace · examples

Static gallery for [`phpstan-type-trace`](../). Left pane = PHP source
(read-only), right pane = the type-inference chain that the real `phpstan-trace`
CLI printed for that file.

**This is not an interactive playground.** Every chain is captured stdout —
nothing executes in the browser. The point is to read 5 representative chains
in 10 seconds without installing anything.

To run it on your own code: `composer require --dev kayw-geek/phpstan-type-trace`.

**v0.2 (planned)** — bundle `php-wasm` + `phpstan.phar` so the right pane
recomputes on edit. Then the name earns the word "playground".

## Dev

```bash
cd examples
npm install
npm run dev
```

## Build

```bash
npm run build
# dist/ is what the GitHub Pages workflow ships
```

## Not shipped to vendor

This directory is `export-ignore`d in `.gitattributes`, so
`composer require kayw-geek/phpstan-type-trace` never downloads it. It only
exists in the git tree.

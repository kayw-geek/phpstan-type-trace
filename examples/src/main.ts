import * as monaco from 'monaco-editor';
import { EXAMPLES, type Example } from './examples';

import editorWorker from 'monaco-editor/esm/vs/editor/editor.worker?worker';

self.MonacoEnvironment = {
  getWorker() {
    return new editorWorker();
  },
};

const picker = document.getElementById('example-picker') as HTMLSelectElement;
const output = document.getElementById('output') as HTMLPreElement;
const status = document.getElementById('status') as HTMLSpanElement;
const editorEl = document.getElementById('editor') as HTMLDivElement;

for (const ex of EXAMPLES) {
  const opt = document.createElement('option');
  opt.value = ex.id;
  opt.textContent = ex.title;
  picker.appendChild(opt);
}

const editor = monaco.editor.create(editorEl, {
  value: EXAMPLES[0].code,
  language: 'php',
  theme: 'vs-dark',
  fontFamily: 'SF Mono, Menlo, monospace',
  fontSize: 13,
  minimap: { enabled: false },
  scrollBeyondLastLine: false,
  automaticLayout: true,
  tabSize: 4,
  readOnly: true,
});

let current: Example = EXAMPLES[0];

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

const ORIGIN_RE = /\b(param|assign-op|assign-ref|assign|array-write|narrow|read)\b/g;
const LINE_RE = /\bL\d+\b/g;
const VIA_RE = /\bvia\s+([A-Za-z_][\w]*)/g;
const ARROW_RE = /=&gt;/g;
const NULL_RE = /\bnull\b/g;
const VAR_RE = /\$[A-Za-z_][\w]*(?:-&gt;[A-Za-z_]\w*)?/g;
const HEADER_RE = /^(# captured from.*)$/gm;
const PROMPT_RE = /^\$ (.+)$/gm;
const COMMENT_RE = /^#(?! captured from).*$/gm;
const RULE_RE = /^─+$/gm;
const SUMMARY_RE = /^\d+ events? · final type:.*$/gm;
const UP_TO_RE = /\(up to L\d+\)/g;

function colorize(raw: string): string {
  let s = escapeHtml(raw);
  s = s.replace(HEADER_RE, (m) => `<span class="muted">${m}</span>`);
  s = s.replace(PROMPT_RE, (_, cmd) => `<span class="muted">$</span> <span class="accent">${cmd}</span>`);
  s = s.replace(COMMENT_RE, (m) => `<span class="muted">${m}</span>`);
  s = s.replace(RULE_RE, (m) => `<span class="muted">${m}</span>`);
  s = s.replace(SUMMARY_RE, (m) => `<span class="muted">${m}</span>`);
  s = s.replace(UP_TO_RE, (m) => `<span class="muted">${m}</span>`);
  s = s.replace(LINE_RE, (m) => `<span class="muted">${m}</span>`);
  s = s.replace(ORIGIN_RE, (m) => `<span class="accent">${m}</span>`);
  s = s.replace(VIA_RE, (_, ext) => `<span class="muted">via</span> <span class="warn">${ext}</span>`);
  s = s.replace(ARROW_RE, () => `<span class="muted">=&gt;</span>`);
  s = s.replace(NULL_RE, () => `<span class="warn">null</span>`);
  s = s.replace(VAR_RE, (m) => `<span class="ok">${m}</span>`);
  return s;
}

function renderOutput(ex: Example): void {
  const text = `$ ${ex.command}\n\n${ex.output}`;
  output.innerHTML = colorize(text);
  status.textContent = `${ex.title} · ${ex.description}`;
}

renderOutput(current);
picker.value = current.id;

picker.addEventListener('change', () => {
  const next = EXAMPLES.find((e) => e.id === picker.value);
  if (!next) return;
  current = next;
  editor.setValue(next.code);
  renderOutput(next);
});

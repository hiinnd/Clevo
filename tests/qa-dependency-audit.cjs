'use strict';
// Read only public package coordinates. Never send configuration, source or secrets.
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
async function audit(packages, ecosystem, artifact, scope) {
  const response = await fetch('https://api.osv.dev/v1/querybatch', {
    method: 'POST', signal: AbortSignal.timeout(45000),
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ queries: packages.map(p => ({ package: { name: p.name, ecosystem }, version: p.version })) }),
  });
  if (!response.ok) throw new Error('OSV returned HTTP ' + response.status);
  const data = await response.json();
  if (!Array.isArray(data.results) || data.results.length !== packages.length || data.results.some(r => r.next_page_token)) throw new Error('Incomplete OSV batch result');
  const findings = data.results.flatMap((r, i) => (r.vulns || []).map(v => ({ package: packages[i].name, version: packages[i].version, advisory: v.id, url: 'https://osv.dev/vulnerability/' + v.id })));
  const result = { executed_at_utc: new Date().toISOString(), source: 'https://api.osv.dev/v1/querybatch', ecosystem, packages_checked: packages.length, findings, scope };
  fs.writeFileSync(path.join(root, '.security-test', artifact), JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result));
  if (findings.length) process.exitCode = 1;
}
async function main() {
  const packages = [];
  let current;
  const flush = () => { if (current?.source === 'hosted') packages.push(current); };
  for (const line of fs.readFileSync(path.join(root, 'mobile/clevo/pubspec.lock'), 'utf8').split(/\r?\n/)) {
    const name = /^  ([a-zA-Z0-9_]+):$/.exec(line);
    if (name) { flush(); current = { name: name[1] }; }
    else if (current) {
      const source = /^    source: (\w+)$/.exec(line);
      const version = /^    version: "([^"]+)"$/.exec(line);
      if (source) current.source = source[1];
      if (version) current.version = version[1];
      if (/^sdks:/.test(line)) { flush(); current = undefined; }
    }
  }
  flush();
  if (packages.length < 20 || packages.some(p => !p.version)) throw new Error('Incomplete Pub lockfile parsing');
  await audit(packages, 'Pub', 'qa-dependency-osv.json', 'Known advisories for locked hosted Pub packages; excludes Flutter/Dart SDK and native Android/iOS transitive dependencies.');
  if (process.argv.includes('--maven')) {
    const bytes = fs.readFileSync(path.join(root, '.security-test/qa-android-dependencies.txt'));
    const report = bytes.toString(bytes[0] === 0xff && bytes[1] === 0xfe ? 'utf16le' : 'utf8').replace(/^\uFEFF/, '');
    if (/\bFAILED\b/.test(report)) throw new Error('Unresolved Android dependencies');
    const maven = new Map();
    for (const line of report.split(/\r?\n/)) {
      if (line.includes('(c)')) continue;
      const m = /(?:\\---|\+---) ([\w.-]+:[\w.-]+)(?::([^\s]+))?(?: -> ([\w.+:-]+))?/.exec(line);
      if (!m) continue;
      let name = m[1], version = m[3] || m[2];
      if (!version) throw new Error('Unresolved Maven version');
      if (version.includes(':')) { const parts = version.split(':'); name = parts.slice(0, -1).join(':'); version = parts.at(-1); }
      if (!/^[0-9][\w.+-]*$/.test(version)) throw new Error('Unsupported Maven version in resolved report');
      maven.set(name + '@' + version, { name, version });
    }
    if (maven.size < 20 || !/BUILD SUCCESSFUL/.test(report)) throw new Error('Incomplete Android runtime dependency report');
    await audit([...maven.values()], 'Maven', 'qa-dependency-maven.json', 'Resolved external coordinates from Android debugRuntimeClasspath; excludes Gradle tooling, embedded/shaded native code and iOS CocoaPods.');
  }
}
main().catch(error => { console.error('Dependency advisory scan incomplete: ' + error.message); process.exitCode = 2; });

const vm = require('vm');
let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { input += chunk; });
process.stdin.on('end', () => {
    let count = 0;
    for (const match of input.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
        if (/\bsrc\s*=/i.test(match[1]) || /application\/(?:ld\+)?json/i.test(match[1])) continue;
        try { new vm.Script(match[2]); count++; }
        catch (error) { process.stdout.write('invalid_script_' + count); process.exitCode = 1; return; }
    }
    process.stdout.write('valid_' + count);
});

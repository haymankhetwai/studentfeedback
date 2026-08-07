const fs = require('fs');
const path = require('path');

function processDir(dir) {
    let modified = 0;
    const files = fs.readdirSync(dir);
    for (const file of files) {
        if (!file.endsWith('.php')) continue;
        if (file === 'index.php') continue;
        
        const fullPath = path.join(dir, file);
        let content = fs.readFileSync(fullPath, 'utf8');
        
        const lines = content.split(/\r?\n/);
        let inBlock = false;
        let newLines = [];
        let changed = false;
        
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            
            if (line.includes('if (!isset($_SESSION[') && line.includes('entry_allowed')) {
                inBlock = true;
                changed = true;
                // Check if previous line is a comment
                if (newLines.length > 0 && newLines[newLines.length - 1].includes('// Prevent direct URL access')) {
                    newLines.pop();
                }
                continue;
            }
            
            if (inBlock) {
                if (line.trim() === '}') {
                    inBlock = false;
                }
                continue;
            }
            
            newLines.push(line);
        }
        
        if (changed) {
            fs.writeFileSync(fullPath, newLines.join('\n'));
            modified++;
            console.log('Modified: ' + fullPath);
        }
    }
    return modified;
}

const total = processDir('teacher') + processDir('student');
console.log('Total files modified: ' + total);

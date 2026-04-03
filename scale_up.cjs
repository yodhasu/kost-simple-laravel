const fs = require('fs');
const path = require('path');

function getFiles(dir, filesList = []) {
    const files = fs.readdirSync(dir);
    for (const file of files) {
        const fullPath = path.join(dir, file);
        if (fs.statSync(fullPath).isDirectory()) {
            getFiles(fullPath, filesList);
        } else if (fullPath.endsWith('.vue')) {
            filesList.push(fullPath);
        }
    }
    return filesList;
}

const vueFiles = getFiles('resources/js');

function scaleUp(str) {
    const textMap = { 'sm':'base', 'base':'lg', 'lg':'xl', 'xl':'2xl', '2xl':'3xl', '3xl': '4xl', '4xl': '5xl', '5xl': '6xl', '6xl': '7xl' };
    
    return str.replace(/(sm|md|lg|xl|2xl):([^'"\s:;>]+)/g, (match, bp, utility) => {
        if (utility.includes('[&')) return match;

        if (utility.includes('rem]')) {
            return match.replace(/\[([\d\.]+)rem\]/g, (m, val) => {
                let num = parseFloat(val);
                let scaled = num / 0.75;
                if (scaled % 1 !== 0) scaled = scaled.toFixed(1);
                if (scaled === "2.0") scaled = "2";
                if (scaled === "28.0") scaled = "28";
                return `[${scaled}rem]`;
            });
        }
        
        if (utility.includes('px]')) {
             return match.replace(/\[([\d\.]+)px\]/g, (m, val) => {
                let num = parseFloat(val);
                let scaled = Math.round(num / 0.75);
                return `[${scaled}px]`;
            });
        }
        
        if (utility.startsWith('text-')) {
            const size = utility.split('text-')[1];
            if (textMap[size]) {
                return `${bp}:text-${textMap[size]}`;
            }
        }
        
        const spacingMap = {
            '1':'1.5', '1.5':'2', '2':'2.5', '2.5':'3', '3':'4', '4':'5', '5':'6', '6':'8', '8':'10', '10':'14', '12':'16', '14':'20', '16':'20', '20':'28', '24':'32', '28':'36', '32':'40', '36':'48', '40':'52', '44':'56', '48':'64', '56':'72', '64':'80', '72':'96', '80':'112'
        };
        
        let matchSpace = utility.match(/^(p|pt|pb|pl|pr|px|py|m|mt|mb|ml|mr|mx|my|gap|w|h|size|max-w|min-h|min-w|gap-x|gap-y|space-x|space-y)-(\d+(\.\d+)?)$/);
        if (matchSpace) {
            let prefix = matchSpace[1];
            let val = matchSpace[2];
            if (spacingMap[val]) {
                return `${bp}:${prefix}-${spacingMap[val]}`;
            }
        }

        const roundedMap = { 'sm': 'md', 'md': 'lg', 'lg': 'xl', 'xl': '2xl', '2xl': '3xl', '3xl': '4xl' };
        let matchRounded = utility.match(/^rounded-(.+)$/);
        if (matchRounded) {
            let val = matchRounded[1];
            if (roundedMap[val]) {
                return `${bp}:rounded-${roundedMap[val]}`;
            }
        }

        return match;
    });
}

vueFiles.forEach(file => {
    let content = fs.readFileSync(file, 'utf8');
    let originalContent = content;
    content = scaleUp(content);
    if (content !== originalContent) {
        fs.writeFileSync(file, content);
        console.log(`Scaled UP ${file}`);
    }
});

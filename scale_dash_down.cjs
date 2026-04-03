const fs = require('fs');

const file = 'resources/js/pages/KostDashboard.vue';
let content = fs.readFileSync(file, 'utf8');
let originalContent = content;

const textMap = { '7xl':'6xl', '6xl':'5xl', '5xl':'4xl', '4xl':'3xl', '3xl': '2xl', '2xl': 'xl', 'xl': 'lg', 'lg': 'base', 'base': 'sm' };

content = content.replace(/(sm|md|lg|xl|2xl):([^'"\s:;>]+)/g, (match, bp, utility) => {
    if (utility.includes('[&')) return match;

    if (utility.includes('rem]')) {
        return match.replace(/\[([\d\.]+)rem\]/g, (m, val) => {
            let num = parseFloat(val);
            let scaled = num * 0.8;
            if (scaled % 1 !== 0) scaled = scaled.toFixed(1);
            return `[${scaled}rem]`;
        });
    }
    
    if (utility.includes('px]')) {
         return match.replace(/\[([\d\.]+)px\]/g, (m, val) => {
            let num = parseFloat(val);
            let scaled = Math.round(num * 0.8);
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
        '1.5': '1', '2': '1.5', '2.5': '2', '3': '2.5', '4': '3', '5': '4', '6': '5', '7': '5.5', '8': '6', '10': '8', '12': '10', '14': '11', '16': '12', '20': '16', '24': '20', '28': '22', '32': '24', '36': '28', '40': '32', '44': '36', '48': '40', '52': '40', '56': '44', '60': '48', '64': '52', '72': '56', '80': '64', '96': '72', '112': '88'
    };
    
    let matchSpace = utility.match(/^(p|pt|pb|pl|pr|px|py|m|mt|mb|ml|mr|mx|my|gap|w|h|size|max-w|min-h|min-w|gap-x|gap-y|space-x|space-y)-(\d+(\.\d+)?)$/);
    if (matchSpace) {
        let prefix = matchSpace[1];
        let val = matchSpace[2];
        if (spacingMap[val]) {
            return `${bp}:${prefix}-${spacingMap[val]}`;
        }
    }

    const roundedMap = { '4xl': '3xl', '3xl': '2xl', '2xl': 'xl', 'xl': 'lg', 'lg': 'md', 'md': 'sm' };
    let matchRounded = utility.match(/^rounded-(.+)$/);
    if (matchRounded) {
        let val = matchRounded[1];
        if (roundedMap[val]) {
            return `${bp}:rounded-${roundedMap[val]}`;
        }
    }

    return match;
});

if (content !== originalContent) {
    fs.writeFileSync(file, content);
    console.log(`Scaled DOWN dashboard by ~20% ${file}`);
}

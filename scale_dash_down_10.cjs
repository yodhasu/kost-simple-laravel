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
            let scaled = num * 0.9;
            if (scaled % 1 !== 0) scaled = scaled.toFixed(2).replace(/0$/, '');
            return `[${scaled}rem]`;
        });
    }
    
    if (utility.includes('px]')) {
         return match.replace(/\[([\d\.]+)px\]/g, (m, val) => {
            let num = parseFloat(val);
            let scaled = Math.round(num * 0.9);
            return `[${scaled}px]`;
        });
    }
    
    // For a minor 10% we keep text shifting as it typically represents ~12-15% 
    if (utility.startsWith('text-')) {
        const size = utility.split('text-')[1];
        if (textMap[size]) {
            return `${bp}:text-${textMap[size]}`;
        }
    }
    
    // Spacing map (shifting down mostly by the smallest interval available)
    const spacingMap = {
        '1.5': '1', '2': '1.5', '2.5': '2', '3': '2.5', '4': '3.5', '5': '4', '6': '5', '7': '6', '8': '7', '10': '9', '12': '10', '14': '12', '16': '14', '20': '18', '24': '20', '28': '24', '32': '28', '36': '32', '40': '36', '44': '40', '48': '44', '52': '48', '56': '48', '60': '52', '64': '56', '72': '64', '80': '72', '96': '80', '112': '96'
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
    console.log(`Scaled DOWN dashboard by ~10% ${file}`);
} else {
    console.log(`No changes made to ${file}`);
}

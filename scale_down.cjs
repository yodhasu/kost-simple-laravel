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

function scaleDown(str) {
    // We only scale down classes starting with sm:, md:, lg:, xl:, 2xl:
    
    // mapping of text sizes
    const textMap = { '7xl':'6xl', '6xl':'5xl', '5xl':'4xl', '4xl':'3xl', '3xl': '2xl', '2xl': 'xl', 'xl': 'lg', 'lg': 'base', 'base': 'sm' };
    
    return str.replace(/(sm|md|lg|xl|2xl):([^'"\s:;>]+)/g, (match, bp, utility) => {
        // Exclude pseudo-classes or arbitrary groups if they somehow leak in
        if (utility.includes('[&')) return match;

        // Custom arbitrary rem values like md:w-[22rem] -> md:w-[16.5rem]
        if (utility.includes('rem]')) {
            let res = match.replace(/\[([\d\.]+)rem\]/g, (m, val) => {
                let num = parseFloat(val);
                let scaled = num * 0.75;
                if (scaled % 1 !== 0) {
                    scaled = scaled.toFixed(1);
                }
                return `[${scaled}rem]`;
            });
            return res;
        }
        
        // Custom arbitrary px values like md:min-h-[14rem] -> we already dealt with rem, let's also do px
        if (utility.includes('px]')) {
             let res = match.replace(/\[([\d\.]+)px\]/g, (m, val) => {
                let num = parseFloat(val);
                let scaled = Math.round(num * 0.75);
                return `[${scaled}px]`;
            });
            return res;
        }
        
        // Text sizes
        if (utility.startsWith('text-')) {
            const size = utility.split('text-')[1];
            if (textMap[size]) {
                return `${bp}:text-${textMap[size]}`;
            }
        }
        
        // Spacing/Sizing scales 
        const spacingMap = {
            '1.5': '1', '2': '1.5', '2.5': '2', '3': '2', '4': '3', '5': '4', '6': '4', '7': '5', '8': '6', '10': '8', '12': '8', '14': '10', '16': '12', '20': '16', '24': '16', '28': '20', '32': '24', '36': '28', '40': '32', '44': '32', '48': '36', '52': '40', '56': '44', '60': '48', '64': '48', '72': '56', '80': '64', '96': '72', '112': '80'
        };
        
        let matchSpace = utility.match(/^(p|pt|pb|pl|pr|px|py|m|mt|mb|ml|mr|mx|my|gap|w|h|size|max-w|min-h|min-w|gap-x|gap-y|space-x|space-y)-(\d+(\.\d+)?)$/);
        if (matchSpace) {
            let prefix = matchSpace[1];
            let val = matchSpace[2];
            if (spacingMap[val]) {
                return `${bp}:${prefix}-${spacingMap[val]}`;
            }
        }

        // Rounded
        const roundedMap = { '4xl': '3xl', '3xl': '2xl', '2xl': 'xl', 'xl': 'lg', 'lg': 'md', 'md': 'sm' };
        let matchRounded = utility.match(/^rounded-(.+)$/);
        if (matchRounded) {
            let val = matchRounded[1];
            if (roundedMap[val]) {
                return `${bp}:rounded-${roundedMap[val]}`;
            }
            // For custom arbitrary rounding like rounded-[2rem] we handle in the rem section if it triggers
        }

        return match;
    });
}

vueFiles.forEach(file => {
    let content = fs.readFileSync(file, 'utf8');
    let originalContent = content;
    
    // Replaces explicit md:rounded-[2rem] with 3xl since the rem logic would make it 1.5rem which is 3xl
    content = scaleDown(content);
    
    if (content !== originalContent) {
        fs.writeFileSync(file, content);
        console.log(`Scaled ${file}`);
    }
});

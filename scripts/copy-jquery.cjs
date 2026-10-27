const fs = require('node:fs');
const path = require('node:path');

const source = path.resolve(__dirname, '../node_modules/jquery/dist/jquery.min.js');
const destinationDirectory = path.resolve(__dirname, '../public/platform/vendor');
const destination = path.join(destinationDirectory, 'jquery.min.js');

fs.mkdirSync(destinationDirectory, { recursive: true });
fs.copyFileSync(source, destination);

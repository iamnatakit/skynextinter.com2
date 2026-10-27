const fs = require('node:fs');
const path = require('node:path');

const source = path.resolve(__dirname, '../node_modules/jquery/dist/jquery.min.js');
const destination = path.resolve(__dirname, '../public/platform/jquery.min.js');
const destinationDirectory = path.dirname(destination);

fs.mkdirSync(destinationDirectory, { recursive: true });
fs.copyFileSync(source, destination);

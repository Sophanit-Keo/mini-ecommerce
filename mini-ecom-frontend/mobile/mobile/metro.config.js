const { getDefaultConfig } = require('expo/metro-config');
const path = require('path');

const config = getDefaultConfig(__dirname);

// The generated API client is a local workspace package one level above the
// mobile app. Tell Metro to watch it when resolving the npm file dependency.
config.watchFolders = [path.resolve(__dirname, '../../lib/api-client-react')];
config.resolver.nodeModulesPaths = [path.resolve(__dirname, 'node_modules')];

module.exports = config;

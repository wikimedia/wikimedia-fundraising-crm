/**
 * @license Copyright (c) 2014-2026, CKSource Holding sp. z o.o. All rights reserved.
 * For licensing, see LICENSE.md or https://ckeditor.com/legal/ckeditor-licensing-options
 */

'use strict';

const path = require( 'path' );

module.exports = {
	devtool: 'source-map',
	performance: { hints: false },
	entry: path.resolve( __dirname, 'src', 'ckeditor.ts' ),
	output: {
		library: 'ClassicEditor',
		path: path.resolve( __dirname, 'build' ),
		filename: 'ckeditor.js',
		libraryTarget: 'umd',
		libraryExport: 'default'
	},
	resolve: {
		extensions: [ '.ts', '.js', '.json' ]
	},
	module: {
		rules: [ {
			test: /\.ts$/,
			use: 'ts-loader'
		}, {
			test: /\.css$/,
			use: [ {
				loader: 'style-loader',
				options: {
					injectType: 'singletonStyleTag',
					attributes: { 'data-cke': true }
				}
			}, 'css-loader' ]
		} ]
	}
};

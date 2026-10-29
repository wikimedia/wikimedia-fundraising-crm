/**
 * Copy CKEditor browser translations next to a self-hosted build.
 */

import { cpSync, mkdirSync, readdirSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';

const sourceDirectory = resolve( 'node_modules/ckeditor5/dist/translations' );
const targetDirectory = resolve( 'build/translations' );

rmSync( targetDirectory, { recursive: true, force: true } );
mkdirSync( targetDirectory, { recursive: true } );

for ( const file of readdirSync( sourceDirectory ) ) {
	if ( file.endsWith( '.umd.js' ) ) {
		cpSync(
			resolve( sourceDirectory, file ),
			resolve( targetDirectory, file.replace( '.umd.js', '.js' ) )
		);
	}
}

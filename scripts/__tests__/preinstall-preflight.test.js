'use strict';

const { getNpmVersion } = require( '../preinstall-preflight' );

describe( 'preinstall preflight helpers', () => {
	test( 'uses the command shell to read npm version on Windows', () => {
		const execFileSync = jest.fn( () => '11.13.0\n' );
		const env = { ComSpec: 'C:\\Windows\\System32\\cmd.exe' };

		expect(
			getNpmVersion( {
				env,
				execFileSync,
				platform: 'win32',
			} )
		).toBe( '11.13.0' );

		expect( execFileSync ).toHaveBeenCalledWith(
			env.ComSpec,
			[ '/d', '/s', '/c', 'npm --version' ],
			{ encoding: 'utf8' }
		);
	} );
} );

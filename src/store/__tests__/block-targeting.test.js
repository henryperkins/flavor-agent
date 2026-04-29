import { resolveActivityBlockTarget } from '../block-targeting';

describe( 'block activity targeting', () => {
	test( 'falls back to the recorded block path when a stale clientId lookup is outside the live tree', () => {
		const staleBlock = {
			clientId: 'old-client-id',
			name: 'core/paragraph',
			attributes: {
				content: 'Before',
			},
		};
		const liveBlock = {
			clientId: 'new-client-id',
			name: 'core/paragraph',
			attributes: {
				content: 'After',
			},
		};

		expect(
			resolveActivityBlockTarget(
				{
					getBlock: () => staleBlock,
					getBlocks: () => [ liveBlock ],
				},
				{
					clientId: 'old-client-id',
					blockPath: [ 0 ],
				}
			)
		).toEqual( {
			block: liveBlock,
			blockPath: [ 0 ],
			resolvedBy: 'blockPath',
		} );
	} );
} );

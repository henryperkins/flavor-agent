// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import AIAdvisorySection from '../AIAdvisorySection';

const { getContainer, getRoot } = setupReactTest();

describe( 'AIAdvisorySection', () => {
	test( 'renders header and count when collapsed', () => {
		act( () => {
			getRoot().render(
				<AIAdvisorySection
					title="Navigation ideas"
					count={ 2 }
					countNoun="idea"
					description="These suggestions stay advisory-only."
				>
					<div>First advisory idea</div>
					<div>Second advisory idea</div>
				</AIAdvisorySection>
			);
		} );

		expect( getContainer().textContent ).toContain( 'Navigation ideas' );
		expect( getContainer().textContent ).toContain( 'Advisory only' );
		expect( getContainer().textContent ).toContain( '2 ideas' );
		// Children are hidden when collapsed (default)
		expect( getContainer().textContent ).not.toContain(
			'First advisory idea'
		);
	} );

	test( 'shows children when initialOpen is true', () => {
		act( () => {
			getRoot().render(
				<AIAdvisorySection
					title="Navigation ideas"
					count={ 2 }
					countNoun="idea"
					description="These suggestions stay advisory-only."
					initialOpen
				>
					<div>First advisory idea</div>
					<div>Second advisory idea</div>
				</AIAdvisorySection>
			);
		} );

		expect( getContainer().textContent ).toContain( 'First advisory idea' );
		expect( getContainer().textContent ).toContain(
			'Second advisory idea'
		);
		expect( getContainer().textContent ).toContain(
			'These suggestions stay advisory-only.'
		);
	} );

	test( 'toggles open on header click', () => {
		act( () => {
			getRoot().render(
				<AIAdvisorySection title="Ideas" count={ 1 } countNoun="idea">
					<div>Content</div>
				</AIAdvisorySection>
			);
		} );

		expect( getContainer().textContent ).not.toContain( 'Content' );

		const toggle = getContainer().querySelector(
			'.flavor-agent-advisory-section__toggle'
		);
		act( () => {
			toggle.click();
		} );

		expect( getContainer().textContent ).toContain( 'Content' );
	} );

	test( 'limits visible children and shows "Show more" button', () => {
		const items = Array.from( { length: 8 }, ( _, i ) => (
			<div key={ i }>Item { i + 1 }</div>
		) );

		act( () => {
			getRoot().render(
				<AIAdvisorySection
					title="Many items"
					count={ 8 }
					countNoun="item"
					initialOpen
					maxVisible={ 5 }
				>
					{ items }
				</AIAdvisorySection>
			);
		} );

		const text = getContainer().textContent;
		expect( text ).toContain( 'Item 1' );
		expect( text ).toContain( 'Item 5' );
		expect( text ).not.toContain( 'Item 6' );
		expect( text ).toContain( 'Show 3 more' );
	} );

	test( 'shows all items after clicking "Show more"', () => {
		const items = Array.from( { length: 8 }, ( _, i ) => (
			<div key={ i }>Item { i + 1 }</div>
		) );

		act( () => {
			getRoot().render(
				<AIAdvisorySection
					title="Many items"
					count={ 8 }
					countNoun="item"
					initialOpen
					maxVisible={ 5 }
				>
					{ items }
				</AIAdvisorySection>
			);
		} );

		const showMoreButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( btn ) => btn.textContent.includes( 'Show' ) );

		act( () => {
			showMoreButton.click();
		} );

		expect( getContainer().textContent ).toContain( 'Item 8' );
		expect( getContainer().textContent ).not.toContain( 'Show' );
	} );

	test( 'flattens nested arrays and fragments while preserving numeric children for overflow counts', () => {
		act( () => {
			getRoot().render(
				<AIAdvisorySection
					title="Many items"
					count={ 4 }
					countNoun="item"
					initialOpen
					maxVisible={ 2 }
				>
					<>
						<div>Item 1</div>
						{ [ 0, <div key="two">Item 2</div>, false, null ] }
						<>
							<div>Item 3</div>
						</>
					</>
				</AIAdvisorySection>
			);
		} );

		const text = getContainer().textContent;
		expect( text ).toContain( 'Item 1' );
		expect( text ).toContain( '0' );
		expect( text ).not.toContain( 'Item 2' );
		expect( text ).not.toContain( 'Item 3' );
		expect( text ).toContain( 'Show 2 more' );
	} );

	test( 'resets overflow back to collapsed when a new advisory result set is rendered', () => {
		const items = Array.from( { length: 8 }, ( _, i ) => (
			<div key={ `first-${ i }` }>First { i + 1 }</div>
		) );

		act( () => {
			getRoot().render(
				<AIAdvisorySection
					title="Many items"
					count={ 8 }
					countNoun="item"
					initialOpen
					maxVisible={ 5 }
				>
					{ items }
				</AIAdvisorySection>
			);
		} );

		const showMoreButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( btn ) => btn.textContent.includes( 'Show' ) );

		act( () => {
			showMoreButton.click();
		} );

		expect( getContainer().textContent ).toContain( 'First 8' );

		const nextItems = Array.from( { length: 7 }, ( _, i ) => (
			<div key={ `next-${ i }` }>Next { i + 1 }</div>
		) );

		act( () => {
			getRoot().render(
				<AIAdvisorySection
					title="Many items"
					count={ 7 }
					countNoun="item"
					initialOpen
					maxVisible={ 5 }
				>
					{ nextItems }
				</AIAdvisorySection>
			);
		} );

		expect( getContainer().textContent ).toContain( 'Next 5' );
		expect( getContainer().textContent ).not.toContain( 'Next 6' );
		expect( getContainer().textContent ).toContain( 'Show 2 more' );
	} );
} );

function joinClassNames( ...values ) {
	return values.filter( Boolean ).join( ' ' );
}

function mockWpComponents( overrides = {} ) {
	const { Fragment, createElement } = require( '@wordpress/element' );

	function Button( {
		children,
		className,
		disabled,
		href,
		label,
		onClick,
		size,
		title,
		variant,
		...props
	} ) {
		void size;
		void variant;

		if ( href ) {
			return createElement(
				'a',
				{
					href,
					className,
					onClick,
					title,
					...props,
				},
				children || label || ''
			);
		}

		return createElement(
			'button',
			{
				type: 'button',
				className,
				disabled,
				onClick,
				title,
				...props,
			},
			children || label || ''
		);
	}

	return {
		Button,
		ButtonGroup: ( { children, className } ) =>
			createElement( 'div', { className }, children ),
		Card: ( { children, className = '' } ) =>
			createElement( 'div', { className }, children ),
		CardBody: ( { children, className = '' } ) =>
			createElement(
				'div',
				{
					className: joinClassNames(
						'components-card__body',
						className
					),
				},
				children
			),
		CardHeader: ( { children, className = '' } ) =>
			createElement(
				'div',
				{
					className: joinClassNames(
						'components-card__header',
						className
					),
				},
				children
			),
		Icon: ( { icon, ...props } ) => {
			void icon;

			return createElement( 'span', {
				'aria-hidden': 'true',
				'data-icon': 'true',
				...props,
			} );
		},
		Notice: ( { children, className = '', onDismiss, status } ) =>
			createElement(
				'div',
				{
					className,
					'data-status': status,
					role: 'alert',
				},
				children,
				onDismiss
					? createElement(
							'button',
							{
								type: 'button',
								'data-dismiss': 'true',
								onClick: onDismiss,
							},
							'Dismiss'
					  )
					: null
			),
		PanelBody: ( { children, title } ) =>
			createElement(
				'section',
				{
					'data-panel': title,
					'data-panel-title': title,
				},
				children
			),
		Spinner: () => createElement( 'div', null, 'Loading…' ),
		TextareaControl: ( {
			className,
			help,
			label,
			onChange = () => {},
			placeholder,
			rows,
			value,
		} ) =>
			createElement(
				'label',
				null,
				label ? createElement( 'span', null, label ) : null,
				createElement( 'textarea', {
					'aria-label': label,
					className,
					placeholder,
					rows,
					value,
					onInput: ( event ) => onChange( event.target.value ),
					onChange: ( event ) => onChange( event.target.value ),
				} ),
				help ? createElement( 'div', null, help ) : null
			),
		Tooltip: ( { children } ) => createElement( Fragment, null, children ),
		...overrides,
	};
}

module.exports = {
	mockWpComponents,
};

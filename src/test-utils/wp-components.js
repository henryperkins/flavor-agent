function joinClassNames( ...values ) {
	return values.filter( Boolean ).join( ' ' );
}

function mockWpComponents( overrides = {} ) {
	const {
		Children,
		cloneElement,
		Fragment,
		createElement,
	} = require( '@wordpress/element' );

	function Button( {
		children,
		className,
		disabled,
		href,
		isPressed,
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
				'aria-pressed': isPressed,
				title,
				...props,
			},
			children || label || ''
		);
	}

	const ToggleGroupControl = ( {
		children,
		className,
		label,
		onChange = () => {},
		value,
	} ) =>
		createElement(
			'div',
			{
				className,
				role: 'group',
				'aria-label': label,
			},
			Children.map( children, ( child ) => {
				if ( ! child?.props ) {
					return child;
				}

				const optionValue = child.props.value;
				const selected = optionValue === value;

				return createElement(
					'button',
					{
						type: 'button',
						className: child.props.className,
						'aria-pressed': selected,
						onClick: () => onChange( optionValue ),
					},
					child.props.label || child.props.children || ''
				);
			} )
		);
	const ToggleGroupControlOption = () => null;

	return {
		Button,
		ButtonGroup: ( { children, className, ...props } ) =>
			createElement(
				'div',
				{ className, role: 'group', ...props },
				children
			),
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
			__nextHasNoMarginBottom,
			className,
			disabled,
			help,
			hideLabelFromVision,
			label,
			onChange = () => {},
			placeholder,
			rows,
			value,
			...props
		} ) => {
			void __nextHasNoMarginBottom;

			return createElement(
				'label',
				null,
				label
					? createElement(
							'span',
							hideLabelFromVision
								? {
										style: {
											position: 'absolute',
											left: '-9999px',
										},
								  }
								: null,
							label
					  )
					: null,
				createElement( 'textarea', {
					'aria-label': label,
					className,
					disabled,
					placeholder,
					rows,
					value,
					onInput: ( event ) => onChange( event.target.value ),
					onChange: ( event ) => onChange( event.target.value ),
					...props,
				} ),
				help ? createElement( 'div', null, help ) : null
			);
		},
		Tooltip: ( { children, text } ) =>
			children?.props
				? cloneElement( children, { title: text } )
				: createElement( Fragment, null, children ),
		ToggleGroupControl,
		ToggleGroupControlOption,
		__experimentalToggleGroupControl: ToggleGroupControl,
		__experimentalToggleGroupControlOption: ToggleGroupControlOption,
		...overrides,
	};
}

module.exports = {
	mockWpComponents,
};

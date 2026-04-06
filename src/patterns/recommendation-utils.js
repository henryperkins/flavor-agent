function cloneArrayValue( value ) {
	if ( Array.isArray( value ) ) {
		return [ ...value ];
	}

	return value;
}

function restoreProperty( pattern, key, value ) {
	if ( Array.isArray( value ) ) {
		pattern[ key ] = [ ...value ];
		return;
	}

	if ( value === undefined ) {
		delete pattern[ key ];
		return;
	}

	pattern[ key ] = value;
}

function extractReasonKeywords( reason ) {
	if ( ! reason ) {
		return [];
	}

	return reason
		.toLowerCase()
		.replace( /[^a-z0-9\s]/g, '' )
		.split( /\s+/ )
		.filter( ( word ) => word.length > 3 );
}

// Patch a pattern array with the current recommendation set.
export function patchPatternMetadata(
	patterns,
	recommendations,
	originalMetadata = new Map(),
	recommendedCategory = 'recommended'
) {
	const safeRecommendations = Array.isArray( recommendations )
		? recommendations
		: [];
	const normalizedRecommendedCategory =
		typeof recommendedCategory === 'string' && recommendedCategory
			? recommendedCategory
			: 'recommended';

	const patched = patterns.map( ( pattern ) => {
		const clone = { ...pattern };

		if ( originalMetadata.has( clone.name ) ) {
			const original = originalMetadata.get( clone.name );

			restoreProperty( clone, 'description', original.description );
			restoreProperty( clone, 'keywords', original.keywords );
			restoreProperty( clone, 'categories', original.categories );
			originalMetadata.delete( clone.name );
		}

		return clone;
	} );

	const recommendationsByName = new Map(
		safeRecommendations.map( ( recommendation ) => [
			recommendation.name,
			recommendation,
		] )
	);

	for ( const pattern of patched ) {
		const recommendation = recommendationsByName.get( pattern.name );

		if ( ! recommendation ) {
			continue;
		}

		originalMetadata.set( pattern.name, {
			description: pattern.description,
			keywords: cloneArrayValue( pattern.keywords ),
			categories: cloneArrayValue( pattern.categories ),
		} );

		const categories = Array.isArray( pattern.categories )
			? pattern.categories.filter(
					( category ) => category !== normalizedRecommendedCategory
			  )
			: [];

		pattern.categories = [ ...categories, normalizedRecommendedCategory ];
		pattern.description = recommendation.reason || pattern.description;

		if ( recommendation.reason ) {
			const mergedKeywords = new Set( [
				...( Array.isArray( pattern.keywords )
					? pattern.keywords
					: [] ),
				...extractReasonKeywords( recommendation.reason ),
			] );

			pattern.keywords = [ ...mergedKeywords ];
		}
	}

	return patched;
}

export function patchPatternCategoryRegistry(
	categories,
	recommendations,
	categoryOwnership = {
		injectedCategories: new Set(),
		registry: null,
	},
	recommendedCategory = 'recommended',
	recommendedLabel = 'Recommended'
) {
	const safeCategories = Array.isArray( categories )
		? categories.map( ( category ) => ( { ...category } ) )
		: [];
	const safeRecommendations = Array.isArray( recommendations )
		? recommendations
		: [];
	const normalizedRecommendedCategory =
		typeof recommendedCategory === 'string' && recommendedCategory
			? recommendedCategory
			: 'recommended';
	const normalizedRecommendedLabel =
		typeof recommendedLabel === 'string' && recommendedLabel
			? recommendedLabel
			: 'Recommended';
	const ownership =
		categoryOwnership &&
		typeof categoryOwnership === 'object' &&
		categoryOwnership.injectedCategories instanceof Set
			? categoryOwnership
			: {
					injectedCategories: new Set(),
					registry: null,
			  };
	const ownsCurrentRegistryCategory = ownership.registry === categories;
	const categoryIndex = safeCategories.findIndex(
		( category ) => category?.name === normalizedRecommendedCategory
	);

	if ( safeRecommendations.length > 0 ) {
		if ( categoryIndex === -1 ) {
			safeCategories.push( {
				name: normalizedRecommendedCategory,
				label: normalizedRecommendedLabel,
			} );
			ownership.injectedCategories.add( normalizedRecommendedCategory );
			ownership.registry = safeCategories;
		} else if (
			ownership.injectedCategories.has( normalizedRecommendedCategory ) &&
			! ownsCurrentRegistryCategory
		) {
			ownership.injectedCategories.delete(
				normalizedRecommendedCategory
			);
			ownership.registry = null;
		}

		return safeCategories;
	}

	if ( ownership.injectedCategories.has( normalizedRecommendedCategory ) ) {
		ownership.injectedCategories.delete( normalizedRecommendedCategory );
		ownership.registry = null;

		if ( ownsCurrentRegistryCategory && categoryIndex !== -1 ) {
			safeCategories.splice( categoryIndex, 1 );
		}
	}

	return safeCategories;
}

// Pick the first high-confidence recommendation reason for the toolbar badge.
export function getPatternBadgeReason( recommendations ) {
	const badge = recommendations.find(
		( recommendation ) => recommendation.score >= 0.9
	);

	return badge ? badge.reason : null;
}

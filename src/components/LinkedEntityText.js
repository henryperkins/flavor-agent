import { Button, Tooltip } from '@wordpress/components';

function findWordMatch(haystack, needle) {
	const lower = haystack.toLowerCase();
	const target = needle.toLowerCase();
	let start = 0;

	while (start <= lower.length - target.length) {
		const idx = lower.indexOf(target, start);
		if (idx === -1) {
			return -1;
		}

		const before = idx === 0 ? '' : haystack[idx - 1];
		const after =
			idx + target.length >= haystack.length
				? ''
				: haystack[idx + target.length];

		const okBefore = before === '' || /[^a-zA-Z0-9\-]/.test(before);
		const okAfter = after === '' || /[^a-zA-Z0-9\-]/.test(after);

		if (okBefore && okAfter) {
			return idx;
		}

		start = idx + 1;
	}

	return -1;
}

export default function LinkedEntityText({
	text = '',
	entities = [],
	onEntityClick = null,
}) {
	if (!text || !Array.isArray(entities) || entities.length === 0) {
		return text || null;
	}

	const segments = [];
	let remaining = text;
	let key = 0;

	while (remaining.length > 0) {
		let bestEntity = null;
		let bestIndex = remaining.length;

		for (const entity of entities) {
			if (!entity?.text) {
				continue;
			}

			const idx = findWordMatch(remaining, entity.text);
			if (idx !== -1 && idx < bestIndex) {
				bestIndex = idx;
				bestEntity = entity;
			}
		}

		if (!bestEntity) {
			segments.push(remaining);
			break;
		}

		if (bestIndex > 0) {
			segments.push(remaining.slice(0, bestIndex));
		}

		const matched = remaining.slice(
			bestIndex,
			bestIndex + bestEntity.text.length
		);
		segments.push(
			<Tooltip key={++key} text={bestEntity.tooltip || bestEntity.text}>
				<Button
					size="small"
					variant="link"
					onClick={() => onEntityClick?.(bestEntity)}
					className={`flavor-agent-inline-link flavor-agent-inline-link--${
						bestEntity.type || 'entity'
					}`}
				>
					{matched}
				</Button>
			</Tooltip>
		);

		remaining = remaining.slice(bestIndex + bestEntity.text.length);
	}

	return <>{segments}</>;
}

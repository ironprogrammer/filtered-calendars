import { __ } from '@wordpress/i18n';

/**
 * How often to re-fetch a source calendar, in minutes. Shared by the editor
 * (a dropdown) and the list (the "refreshes …" hint). Values must match
 * Store::ALLOWED_REFRESH on the PHP side.
 */
export const DEFAULT_REFRESH = 1440;

export const REFRESH_OPTIONS = [
	{ value: '15', label: __( 'Every 15 minutes', 'filtered-calendars' ) },
	{ value: '60', label: __( 'Hourly', 'filtered-calendars' ) },
	{ value: '360', label: __( 'Every 6 hours', 'filtered-calendars' ) },
	{ value: '1440', label: __( 'Daily', 'filtered-calendars' ) },
	{ value: '10080', label: __( 'Weekly', 'filtered-calendars' ) },
];

const SHORT_LABELS = {
	15: __( 'every 15 min', 'filtered-calendars' ),
	60: __( 'hourly', 'filtered-calendars' ),
	360: __( 'every 6 hours', 'filtered-calendars' ),
	1440: __( 'daily', 'filtered-calendars' ),
	10080: __( 'weekly', 'filtered-calendars' ),
};

/**
 * A short, lowercase cadence label for the list view, e.g. "daily".
 *
 * @param {number} minutes Refresh interval in minutes.
 * @return {string} Human label, falling back to the default.
 */
export function refreshLabel( minutes ) {
	return SHORT_LABELS[ minutes ] || SHORT_LABELS[ DEFAULT_REFRESH ];
}

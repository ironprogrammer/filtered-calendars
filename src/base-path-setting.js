import {
	Button,
	__experimentalInputControl as InputControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalText as Text,
	__experimentalHeading as Heading,
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const data = window.filteredCalendarsData || {};
const HOME_URL = data.homeUrl || '/';
const DEFAULT_BASE = data.defaultBase || 'filtered-calendars';

/**
 * Slugify to match the server's sanitize_title so the preview URL is honest.
 *
 * @param {string} value Raw input.
 * @return {string} URL-safe slug.
 */
function slugify( value ) {
	return String( value )
		.toLowerCase()
		.trim()
		.replace( /[\s_]+/g, '-' )
		.replace( /[^a-z0-9-]/g, '' )
		.replace( /-+/g, '-' )
		.replace( /^-|-$/g, '' );
}

/**
 * Compact editor for the URL path segment calendars are served under.
 *
 * @param {Object}   props
 * @param {string}   props.base     Current stored value.
 * @param {Function} props.onSave   Persist a new value.
 * @param {boolean}  props.isSaving
 */
export default function BasePathSetting( { base, onSave, isSaving } ) {
	const effectiveBase = base || DEFAULT_BASE;
	const [ draft, setDraft ] = useState( effectiveBase );

	// Keep the field in sync if the stored value changes elsewhere.
	useEffect( () => {
		setDraft( effectiveBase );
	}, [ effectiveBase ] );

	const cleaned = slugify( draft ) || DEFAULT_BASE;
	const dirty = cleaned !== effectiveBase;

	return (
		<VStack
			spacing={ 2 }
			style={ {
				marginTop: '8px',
				paddingTop: '20px',
				borderTop: '1px solid #e0e0e0',
			} }
		>
			<Heading level={ 3 } weight={ 500 }>
				{ __( 'Calendar URL path', 'filtered-calendars' ) }
			</Heading>
			<HStack
				justify="flex-start"
				alignment="flex-start"
				spacing={ 3 }
				wrap
			>
				<div style={ { flexGrow: 1, maxWidth: '520px' } }>
					<InputControl
						className="fc-base-path-input"
						value={ draft }
						onChange={ ( value ) => setDraft( value ?? '' ) }
						prefix={
							<Text
								variant="muted"
								style={ {
									paddingLeft: '8px',
									fontFamily: 'Menlo, Consolas, monospace',
								} }
							>
								{ HOME_URL }
							</Text>
						}
						__next40pxDefaultSize
					/>
				</div>
				<Button
					variant="secondary"
					disabled={ ! dirty || isSaving }
					isBusy={ isSaving }
					onClick={ () => onSave( cleaned ) }
					__next40pxDefaultSize
				>
					{ __( 'Update path', 'filtered-calendars' ) }
				</Button>
			</HStack>
			<Text variant="muted" size="small">
				{ __(
					'Calendars are served at this path, e.g.',
					'filtered-calendars'
				) }
				<code>{ HOME_URL + cleaned + '/your-calendar/' }</code>
			</Text>
		</VStack>
	);
}

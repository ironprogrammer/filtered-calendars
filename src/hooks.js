import { useEntityProp, store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Read and persist the calendar list through the core site-settings entity.
 *
 * Returns the current calendars, a saver, and save/loading state.
 */
export function useFeeds() {
	const [ feeds = [], setFeeds ] = useEntityProp(
		'root',
		'site',
		'filtered_calendars_configs'
	);

	const { saveEditedEntityRecord } = useDispatch( coreStore );

	const isSaving = useSelect(
		( select ) =>
			select( coreStore ).isSavingEntityRecord( 'root', 'site' ),
		[]
	);

	const hasResolved = useSelect(
		( select ) =>
			select( coreStore ).hasFinishedResolution( 'getEntityRecord', [
				'root',
				'site',
				undefined,
			] ),
		[]
	);

	// Persist a new list: stage the edit, then save.
	const saveFeeds = useCallback(
		async ( next ) => {
			setFeeds( next );
			return saveEditedEntityRecord( 'root', 'site' );
		},
		[ setFeeds, saveEditedEntityRecord ]
	);

	return { feeds: feeds || [], saveFeeds, isSaving, hasResolved };
}

/**
 * Read and persist the URL path segment calendars live under.
 *
 * Returns the staged value, a live setter (edits only), and a saver.
 */
export function useBasePath() {
	const [ base, setBase ] = useEntityProp(
		'root',
		'site',
		'filtered_calendars_base'
	);
	const { saveEditedEntityRecord } = useDispatch( coreStore );

	const saveBase = useCallback(
		async ( next ) => {
			setBase( next );
			return saveEditedEntityRecord( 'root', 'site' );
		},
		[ setBase, saveEditedEntityRecord ]
	);

	return { base: base || '', setBase, saveBase };
}

/**
 * Load diagnostic stats keyed by calendar slug. Refetches when `nonce` changes.
 *
 * @param {number} nonce Bump to force a refetch.
 * @return {Object} Stats keyed by calendar slug.
 */
export function useStats( nonce ) {
	const [ stats, setStats ] = useState( {} );

	useEffect( () => {
		let active = true;
		apiFetch( { path: 'filtered-calendars/v1/stats' } )
			.then( ( data ) => {
				if ( active ) {
					setStats( data || {} );
				}
			} )
			.catch( () => {
				if ( active ) {
					setStats( {} );
				}
			} );
		return () => {
			active = false;
		};
	}, [ nonce ] );

	return stats;
}

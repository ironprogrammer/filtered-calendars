import { DataViews } from '@wordpress/dataviews';
import {
	Button,
	Notice,
	Spinner,
	Tooltip,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalHeading as Heading,
	__experimentalText as Text,
} from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import { external, check, copy, pencil, trash, plus } from '@wordpress/icons';
import FeedEditModal from './feed-edit-modal';
import BasePathSetting from './base-path-setting';
import { useFeeds, useStats, useBasePath } from './hooks';

const HOME_URL =
	( window.filteredCalendarsData && window.filteredCalendarsData.homeUrl ) ||
	'/';
const DEFAULT_BASE =
	( window.filteredCalendarsData &&
		window.filteredCalendarsData.defaultBase ) ||
	'filtered-calendars';

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 10,
	search: '',
	filters: [],
	sort: { field: 'name', direction: 'asc' },
	fields: [ 'url', 'subscribe', 'filtered' ],
	titleField: 'name',
};

/**
 * Copy-to-clipboard button for a calendar's subscribe URL, plus an open link.
 *
 * @param {Object} props
 * @param {string} props.url Subscribe URL.
 */
function SubscribeCell( { url } ) {
	const [ copied, setCopied ] = useState( false );

	if ( ! url ) {
		return <Text variant="muted">—</Text>;
	}

	const doCopy = async () => {
		try {
			await navigator.clipboard.writeText( url );
			setCopied( true );
			setTimeout( () => setCopied( false ), 1500 );
		} catch {}
	};

	return (
		<HStack
			className="fc-subscribe-actions"
			justify="flex-start"
			spacing={ 0 }
			expanded={ false }
		>
			<Tooltip
				text={
					copied
						? __( 'Copied!', 'filtered-calendars' )
						: __( 'Copy subscribe URL', 'filtered-calendars' )
				}
			>
				<Button
					size="compact"
					variant="tertiary"
					icon={ copied ? check : copy }
					iconSize={ 20 }
					onClick={ doCopy }
					label={ __( 'Copy subscribe URL', 'filtered-calendars' ) }
				/>
			</Tooltip>
			<Button
				size="compact"
				variant="tertiary"
				href={ url }
				target="_blank"
				rel="noreferrer"
				icon={ external }
				iconSize={ 20 }
				label={ __( 'Open calendar', 'filtered-calendars' ) }
			/>
		</HStack>
	);
}

/**
 * Diagnostic cell: how many events were dropped on the last fetch / cumulatively.
 *
 * @param {Object} props
 * @param {Object} props.stat Per-calendar stats record.
 */
function FilteredCell( { stat } ) {
	if ( ! stat || ! stat.last_fetch ) {
		return (
			<Text variant="muted">
				{ __( 'Not fetched yet', 'filtered-calendars' ) }
			</Text>
		);
	}

	let status = '';
	if ( stat.status === 'stale' ) {
		status = __(
			'(served from cache — upstream error)',
			'filtered-calendars'
		);
	} else if ( stat.status === 'parse_error' ) {
		status = __( '(passed through — parse error)', 'filtered-calendars' );
	}

	return (
		<VStack spacing={ 0 }>
			<Text>
				{ sprintf(
					/* translators: 1: dropped last fetch, 2: kept last fetch. */
					__( 'Dropped %1$d, kept %2$d', 'filtered-calendars' ),
					stat.last_dropped,
					stat.last_kept
				) }
			</Text>
			<Text variant="muted" size="small">
				{ sprintf(
					/* translators: %d: cumulative dropped count. */
					__( '%d dropped all-time', 'filtered-calendars' ),
					stat.total_dropped
				) }{ ' ' }
				{ status }
			</Text>
		</VStack>
	);
}

export default function App() {
	const { feeds, saveFeeds, isSaving, hasResolved } = useFeeds();
	const { base, saveBase } = useBasePath();
	const [ statsNonce, setStatsNonce ] = useState( 0 );
	const stats = useStats( statsNonce );

	const feedBase = HOME_URL + ( base || DEFAULT_BASE ) + '/';

	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ editing, setEditing ] = useState( null ); // calendar object or {} for new
	const [ notice, setNotice ] = useState( null );

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Name', 'filtered-calendars' ),
				enableGlobalSearch: true,
				render: ( { item } ) => <strong>{ item.name }</strong>,
			},
			{
				id: 'url',
				label: __( 'Source', 'filtered-calendars' ),
				enableGlobalSearch: true,
				render: ( { item } ) => (
					<Text variant="muted" size="small">
						{ item.url }
					</Text>
				),
			},
			{
				id: 'subscribe',
				label: __( 'Subscribe URL', 'filtered-calendars' ),
				enableSorting: false,
				render: ( { item } ) => (
					<SubscribeCell
						url={ item.id ? feedBase + item.id + '/' : '' }
					/>
				),
			},
			{
				id: 'filtered',
				label: __( 'Filtered', 'filtered-calendars' ),
				enableSorting: false,
				render: ( { item } ) => (
					<FilteredCell stat={ stats[ item.id ] } />
				),
			},
		],
		[ stats, feedBase ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'filtered-calendars' ),
				icon: pencil,
				isPrimary: true,
				callback: ( items ) => setEditing( items[ 0 ] ),
			},
			{
				id: 'delete',
				label: __( 'Delete', 'filtered-calendars' ),
				icon: trash,
				isDestructive: true,
				callback: async ( items ) => {
					const ids = items.map( ( i ) => i.id );
					const next = feeds.filter(
						( f ) => ! ids.includes( f.id )
					);
					try {
						await saveFeeds( next );
						setStatsNonce( ( n ) => n + 1 );
						setNotice( {
							status: 'success',
							message: __(
								'Calendar deleted.',
								'filtered-calendars'
							),
						} );
					} catch ( e ) {
						setNotice( {
							status: 'error',
							message:
								e.message ||
								__(
									'Could not delete calendar.',
									'filtered-calendars'
								),
						} );
					}
				},
			},
		],
		[ feeds, saveFeeds ]
	);

	const { data, paginationInfo } = useMemo( () => {
		let rows = [ ...feeds ];
		const q = ( view.search || '' ).toLowerCase();
		if ( q ) {
			rows = rows.filter(
				( f ) =>
					( f.name || '' ).toLowerCase().includes( q ) ||
					( f.url || '' ).toLowerCase().includes( q )
			);
		}
		if ( view.sort && view.sort.field === 'name' ) {
			const dir = view.sort.direction === 'desc' ? -1 : 1;
			rows.sort(
				( a, b ) => dir * ( a.name || '' ).localeCompare( b.name || '' )
			);
		}
		const total = rows.length;
		const totalPages = Math.max( 1, Math.ceil( total / view.perPage ) );
		const start = ( view.page - 1 ) * view.perPage;
		return {
			data: rows.slice( start, start + view.perPage ),
			paginationInfo: { totalItems: total, totalPages },
		};
	}, [ feeds, view ] );

	const onSave = async ( draft ) => {
		const exists = draft.id && feeds.some( ( f ) => f.id === draft.id );
		const next = exists
			? feeds.map( ( f ) => ( f.id === draft.id ? draft : f ) )
			: [ ...feeds, draft ];
		try {
			await saveFeeds( next );
			setEditing( null );
			setStatsNonce( ( n ) => n + 1 );
			setNotice( {
				status: 'success',
				message: __( 'Calendar saved.', 'filtered-calendars' ),
			} );
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message:
					e.message ||
					__( 'Could not save calendar.', 'filtered-calendars' ),
			} );
		}
	};

	const onSaveBase = async ( next ) => {
		try {
			await saveBase( next );
			setNotice( {
				status: 'success',
				message: __(
					'Calendar path updated. Reload if a URL 404s.',
					'filtered-calendars'
				),
			} );
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message:
					e.message ||
					__( 'Could not update path.', 'filtered-calendars' ),
			} );
		}
	};

	if ( ! hasResolved ) {
		return <Spinner />;
	}

	const totalDropped = Object.values( stats ).reduce(
		( sum, s ) => sum + ( s.total_dropped || 0 ),
		0
	);

	return (
		<VStack spacing={ 4 }>
			<HStack justify="space-between" alignment="center">
				<VStack spacing={ 1 }>
					<Heading level={ 1 }>
						{ __( 'Filtered Calendars', 'filtered-calendars' ) }
					</Heading>
					<Text variant="muted">
						{ feeds.length > 0
							? sprintf(
									/* translators: 1: calendar count, 2: total dropped. */
									_n(
										'%1$d calendar · %2$d events dropped all-time',
										'%1$d calendars · %2$d events dropped all-time',
										feeds.length,
										'filtered-calendars'
									),
									feeds.length,
									totalDropped
							  )
							: __(
									'Re-serve external calendars with unwanted events removed.',
									'filtered-calendars'
							  ) }
					</Text>
				</VStack>
				<Button
					variant="primary"
					icon={ plus }
					onClick={ () => setEditing( {} ) }
				>
					{ __( 'Add calendar', 'filtered-calendars' ) }
				</Button>
			</HStack>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			{ feeds.length === 0 ? (
				<VStack
					spacing={ 3 }
					alignment="center"
					style={ {
						padding: '48px 24px',
						border: '1px dashed #c3c4c7',
						borderRadius: '4px',
						textAlign: 'center',
					} }
				>
					<Heading level={ 3 } weight={ 500 }>
						{ __(
							'No calendars set up yet',
							'filtered-calendars'
						) }
					</Heading>
					<Text variant="muted">
						{ __(
							'Add a calendar to start re-serving it with unwanted events removed.',
							'filtered-calendars'
						) }
					</Text>
					<Button
						variant="primary"
						icon={ plus }
						onClick={ () => setEditing( {} ) }
					>
						{ __(
							'Add your first calendar',
							'filtered-calendars'
						) }
					</Button>
				</VStack>
			) : (
				<>
					<DataViews
						data={ data }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						actions={ actions }
						paginationInfo={ paginationInfo }
						getItemId={ ( item ) => item.id || item.url }
						defaultLayouts={ { table: {} } }
						isLoading={ isSaving }
					/>
					<Text variant="muted" size="small">
						{ __(
							'All calendars are also listed at',
							'filtered-calendars'
						) }
						<a href={ feedBase } target="_blank" rel="noreferrer">
							{ feedBase }
						</a>
					</Text>
				</>
			) }

			<BasePathSetting
				base={ base }
				onSave={ onSaveBase }
				isSaving={ isSaving }
			/>

			{ editing && (
				<FeedEditModal
					feed={ editing }
					onSave={ onSave }
					onClose={ () => setEditing( null ) }
					isSaving={ isSaving }
				/>
			) }
		</VStack>
	);
}

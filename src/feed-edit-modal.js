import {
	Modal,
	Button,
	TextControl,
	TextareaControl,
	Notice,
	Flex,
	FlexItem,
	Spinner,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { useState, useRef, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const PLACEHOLDER = `board
budget
# lines starting with # are ignored; * is a wildcard`;

/**
 * Add/edit modal for a single calendar, with an inline dry-run preview.
 *
 * Source URL comes first: when it loses focus we fetch the calendar and, if the
 * name is still empty, prefill it from the calendar's own name (X-WR-CALNAME).
 *
 * @param {Object}   props
 * @param {Object}   props.feed     Calendar being edited (may be a blank draft).
 * @param {Function} props.onSave   Called with the edited calendar.
 * @param {Function} props.onClose  Dismiss without saving.
 * @param {boolean}  props.isSaving
 */
export default function FeedEditModal( { feed, onSave, onClose, isSaving } ) {
	const [ draft, setDraft ] = useState( {
		id: feed.id || '',
		name: feed.name || '',
		url: feed.url || '',
		keywords: feed.keywords || '',
	} );
	const [ preview, setPreview ] = useState( null );
	const [ previewing, setPreviewing ] = useState( false );
	const [ previewError, setPreviewError ] = useState( '' );
	const [ resolvingName, setResolvingName ] = useState( false );
	const urlRef = useRef();

	// Put the cursor in the URL field when the modal opens. A rAF lets the
	// Modal finish its own focus-on-mount handling first, so ours wins.
	useEffect( () => {
		const raf = window.requestAnimationFrame( () => {
			urlRef.current?.querySelector( 'input' )?.focus();
		} );
		return () => window.cancelAnimationFrame( raf );
	}, [] );

	const isNew = ! feed.id;
	const canSave = draft.url.trim() !== '';

	// Fetch the calendar once the URL is entered and prefill the name when empty.
	const resolveName = async () => {
		const url = draft.url.trim();
		if ( ! url || draft.name.trim() !== '' ) {
			return;
		}
		setResolvingName( true );
		try {
			const result = await apiFetch( {
				path: 'filtered-calendars/v1/preview',
				method: 'POST',
				data: { url, keywords: '' },
			} );
			setDraft( ( d ) => {
				const next = { ...d };
				if ( result.resolved_url && result.resolved_url !== next.url ) {
					next.url = result.resolved_url;
				}
				if ( result.title && next.name.trim() === '' ) {
					next.name = result.title;
				}
				return next;
			} );
		} catch {
			// Non-fatal: the user can still type a name manually.
		} finally {
			setResolvingName( false );
		}
	};

	const runPreview = async () => {
		setPreviewing( true );
		setPreviewError( '' );
		setPreview( null );
		try {
			const result = await apiFetch( {
				path: 'filtered-calendars/v1/preview',
				method: 'POST',
				data: { url: draft.url, keywords: draft.keywords },
			} );
			setPreview( result );
			setDraft( ( d ) => {
				const next = { ...d };
				if ( result.resolved_url && result.resolved_url !== next.url ) {
					next.url = result.resolved_url;
				}
				if ( result.title && next.name.trim() === '' ) {
					next.name = result.title;
				}
				return next;
			} );
		} catch ( err ) {
			setPreviewError(
				err.message || __( 'Preview failed.', 'filtered-calendars' )
			);
		} finally {
			setPreviewing( false );
		}
	};

	return (
		<Modal
			title={
				isNew
					? __( 'Add calendar', 'filtered-calendars' )
					: __( 'Edit calendar', 'filtered-calendars' )
			}
			onRequestClose={ onClose }
			style={ { maxWidth: '640px' } }
		>
			<VStack spacing={ 4 }>
				<div ref={ urlRef }>
					<TextControl
						label={ __(
							'Source calendar URL (.ics)',
							'filtered-calendars'
						) }
						type="url"
						placeholder="https://example.com/calendar.ics"
						value={ draft.url }
						onChange={ ( url ) => setDraft( { ...draft, url } ) }
						onBlur={ resolveName }
						help={ __(
							'The public iCalendar (.ics) URL. webcal:// links work too.',
							'filtered-calendars'
						) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</div>

				<div>
					<TextControl
						label={ __( 'Name', 'filtered-calendars' ) }
						help={ __(
							'Shown in your list. Filled in from the calendar automatically — edit if you like.',
							'filtered-calendars'
						) }
						value={ draft.name }
						onChange={ ( name ) => setDraft( { ...draft, name } ) }
						disabled={ resolvingName }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					{ resolvingName && (
						<HStack justify="flex-start" spacing={ 1 }>
							<Spinner />
							<Text variant="muted" size="small">
								{ __(
									'Reading calendar name…',
									'filtered-calendars'
								) }
							</Text>
						</HStack>
					) }
				</div>

				<TextareaControl
					label={ __( 'Drop events matching', 'filtered-calendars' ) }
					help={ __(
						"One keyword per line. Case-insensitive. Each event's name, location, notes, and categories are checked. Use * as a wildcard.",
						'filtered-calendars'
					) }
					rows={ 8 }
					placeholder={ PLACEHOLDER }
					value={ draft.keywords }
					onChange={ ( keywords ) =>
						setDraft( { ...draft, keywords } )
					}
					__nextHasNoMarginBottom
				/>

				<HStack justify="flex-start">
					<Button
						variant="secondary"
						onClick={ runPreview }
						disabled={ ! canSave || previewing }
					>
						{ __( 'Preview filter', 'filtered-calendars' ) }
					</Button>
					{ previewing && <Spinner /> }
				</HStack>

				{ previewError && (
					<Notice status="error" isDismissible={ false }>
						{ previewError }
					</Notice>
				) }

				{ preview && ! previewError && (
					<Notice status="info" isDismissible={ false }>
						<Text>
							{ sprintf(
								/* translators: 1: dropped count, 2: total count. */
								__(
									'Would drop %1$d of %2$d events.',
									'filtered-calendars'
								),
								preview.dropped,
								preview.total
							) }
						</Text>
						{ preview.dropped_titles.length > 0 && (
							<ul
								style={ {
									margin: '8px 0 0',
									paddingLeft: '20px',
									listStyleType: 'disc',
								} }
							>
								{ preview.dropped_titles.map( ( title, i ) => (
									<li
										key={ i }
										style={ { listStyleType: 'disc' } }
									>
										{ title }
									</li>
								) ) }
							</ul>
						) }
					</Notice>
				) }

				<Flex justify="flex-end">
					<FlexItem>
						<Button variant="tertiary" onClick={ onClose }>
							{ __( 'Cancel', 'filtered-calendars' ) }
						</Button>
					</FlexItem>
					<FlexItem>
						<Button
							variant="primary"
							onClick={ () => onSave( draft ) }
							disabled={ ! canSave || isSaving }
							isBusy={ isSaving }
						>
							{ __( 'Save', 'filtered-calendars' ) }
						</Button>
					</FlexItem>
				</Flex>
			</VStack>
		</Modal>
	);
}

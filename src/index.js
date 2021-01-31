/* global humEditorObject */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import {
	ClipboardButton,
	TextControl
} from '@wordpress/components';
import { withState } from '@wordpress/compose';

const HumGutenbergShortlinkPanel = withState( {
	hasCopied: false,
} )( ( { hasCopied, setState } ) => (
	<PluginDocumentSettingPanel
		name="shortlink-panel"
		title="Shortlink"
		className="shortlink-panel"
	>
		<TextControl
			label={ humEditorObject.inputLabel }
			hideLabelFromVision="true"
			value={ humEditorObject.shortlink }
			disabled
		/>
		<ClipboardButton
			isPrimary
			text={ humEditorObject.shortlink }
			onCopy={ () => setState( { hasCopied: true } ) }
			onFinishCopy={ () => setState( { hasCopied: false } ) }
		>
			{ hasCopied ? humEditorObject.copyButtonCopiedLabel : humEditorObject.copyButtonLabel }
		</ClipboardButton>
	</PluginDocumentSettingPanel>
) );

registerPlugin( 'hum-gutenberg-shortlink-panel', {
	render: HumGutenbergShortlinkPanel,
	icon: '',
} );

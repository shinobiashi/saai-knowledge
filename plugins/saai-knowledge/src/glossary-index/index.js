import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';
import './editor.scss';

function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<ServerSideRender block={ metadata.name } />
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
